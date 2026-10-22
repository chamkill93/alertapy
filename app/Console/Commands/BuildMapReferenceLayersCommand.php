<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;

class BuildMapReferenceLayersCommand extends Command
{
    protected $signature = 'alertapy:map-layers:build
        {--roads= : GeoJSON de vías principales del INE}
        {--rivers= : GeoJSON de hidrografía del INE}
        {--output= : Directorio público de salida}';

    protected $description = 'Genera capas GeoJSON locales, agrupadas y simplificadas, para la red vial y los ríos.';

    public function handle(): int
    {
        $roadsPath = $this->absolutePath(
            (string) ($this->option('roads') ?: storage_path('app/imports/roads/ine-vias-principales-2022.geojson')),
        );
        $riversPath = $this->absolutePath(
            (string) ($this->option('rivers') ?: storage_path('app/imports/roads/ine-hidrografia-nombrada.geojson')),
        );
        $outputPath = $this->absolutePath(
            (string) ($this->option('output') ?: public_path('data/map-layers')),
            false,
        );

        try {
            File::ensureDirectoryExists($outputPath);

            $roads = $this->buildRoads($this->features($roadsPath));
            $rivers = $this->buildRivers($this->features($riversPath));

            $this->writeGeoJson($outputPath.DIRECTORY_SEPARATOR.'roads.geojson', $roads, [
                'title' => 'Vías principales de Paraguay',
                'source' => 'Instituto Nacional de Estadística (INE)',
                'source_url' => 'https://geonode.ine.gov.py/layers/geonode%3AVias_Principales_2022',
            ]);
            $this->writeGeoJson($outputPath.DIRECTORY_SEPARATOR.'rivers.geojson', $rivers, [
                'title' => 'Red hidrográfica de Paraguay',
                'source' => 'Instituto Nacional de Estadística (INE)',
                'source_url' => 'https://geonode.ine.gov.py/layers/geonode%3AHidrografia',
            ]);

            $this->table(
                ['Capa', 'Elementos visibles', 'Archivo'],
                [
                    ['Red vial', count($roads), $outputPath.DIRECTORY_SEPARATOR.'roads.geojson'],
                    ['Ríos', count($rivers), $outputPath.DIRECTORY_SEPARATOR.'rivers.geojson'],
                ],
            );

            return self::SUCCESS;
        } catch (RuntimeException|JsonException $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function features(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException("No se puede leer el archivo cartográfico: {$path}");
        }

        $payload = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        if (($payload['type'] ?? null) !== 'FeatureCollection' || ! is_array($payload['features'] ?? null)) {
            throw new RuntimeException("El archivo no es una colección GeoJSON válida: {$path}");
        }

        return $payload['features'];
    }

    /** @param array<int, array<string, mixed>> $features
     *  @return array<int, array<string, mixed>>
     */
    private function buildRoads(array $features): array
    {
        $groups = [];

        foreach ($features as $feature) {
            $properties = (array) ($feature['properties'] ?? []);
            $name = trim((string) ($properties['NOMBRE'] ?? ''));
            $classification = str_contains($this->plain((string) ($properties['DesTipoVia'] ?? '')), 'DEPARTAMENTAL')
                ? 'departamental'
                : 'nacional';
            $code = preg_match('/\b(PY\d{2}|D\d{3})\b/i', $name, $match) === 1 ? strtoupper($match[1]) : null;
            $label = $code ?: ($name !== '' ? $name : 'Vía principal sin nombre');
            $key = $classification.'|'.$this->plain($label);

            $groups[$key] ??= [
                'name' => $label,
                'code' => $code,
                'classification' => $classification,
                'surfaces' => [],
                'segments' => 0,
                'lines' => [],
            ];
            $surface = trim((string) ($properties['DesRodaVia'] ?? ''));
            if ($surface !== '') {
                $groups[$key]['surfaces'][$surface] = true;
            }
            $groups[$key]['segments']++;
            array_push($groups[$key]['lines'], ...$this->lines((array) ($feature['geometry'] ?? [])));
        }

        return $this->featuresFromGroups($groups, 0.00035, 'roads');
    }

    /** @param array<int, array<string, mixed>> $features
     *  @return array<int, array<string, mixed>>
     */
    private function buildRivers(array $features): array
    {
        $groups = [];

        foreach ($features as $feature) {
            $properties = (array) ($feature['properties'] ?? []);
            $name = trim((string) ($properties['NOMBRE'] ?? ''));
            if ($name === '') {
                continue;
            }
            $displayName = mb_convert_case(mb_strtolower($name, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
            $displayName = preg_replace('/^Rio\b/u', 'Río', $displayName) ?: $displayName;
            $key = $this->plain($displayName);
            $plainName = $this->plain($displayName);
            $classification = match (true) {
                str_starts_with($plainName, 'RIO ') => 'river',
                str_starts_with($plainName, 'ARROYO '), str_starts_with($plainName, 'RIACHO ') => 'stream',
                str_starts_with($plainName, 'LAGO '), str_starts_with($plainName, 'LAGUNA ') => 'lake',
                str_starts_with($plainName, 'CANAL ') => 'canal',
                default => 'waterway',
            };
            $groups[$key] ??= [
                'name' => $displayName,
                'code' => null,
                'classification' => $classification,
                'surfaces' => [],
                'segments' => 0,
                'lines' => [],
            ];
            $groups[$key]['segments']++;
            array_push($groups[$key]['lines'], ...$this->lines((array) ($feature['geometry'] ?? [])));
        }

        return $this->featuresFromGroups($groups, 0.0007, 'rivers');
    }

    /** @param array<string, array<string, mixed>> $groups
     *  @return array<int, array<string, mixed>>
     */
    private function featuresFromGroups(array $groups, float $tolerance, string $layer): array
    {
        $features = [];

        foreach ($groups as $group) {
            $lines = array_values(array_filter(array_map(
                fn (array $line): array => $this->simplifyLine($line, $tolerance),
                $this->stitchLines($group['lines']),
            ), fn (array $line): bool => count($line) >= 2));

            if ($lines === []) {
                continue;
            }

            $properties = [
                'layer' => $layer,
                'name' => $group['name'],
                'code' => $group['code'],
                'classification' => $group['classification'],
                'source' => 'Instituto Nacional de Estadística (INE)',
                'source_segments' => $group['segments'],
            ];
            if ($group['surfaces'] !== []) {
                $properties['surface'] = implode(', ', array_keys($group['surfaces']));
            }

            $features[] = [
                'type' => 'Feature',
                'properties' => $properties,
                'geometry' => count($lines) === 1
                    ? ['type' => 'LineString', 'coordinates' => $lines[0]]
                    : ['type' => 'MultiLineString', 'coordinates' => $lines],
            ];
        }

        usort($features, fn (array $left, array $right): int => strcmp(
            (string) ($left['properties']['name'] ?? ''),
            (string) ($right['properties']['name'] ?? ''),
        ));

        return $features;
    }

    /** @return array<int, array<int, array{0: float, 1: float}>> */
    private function lines(array $geometry): array
    {
        $coordinates = (array) ($geometry['coordinates'] ?? []);
        $lines = ($geometry['type'] ?? null) === 'LineString' ? [$coordinates] : $coordinates;

        return array_values(array_filter(array_map(function (mixed $line): array {
            if (! is_array($line)) {
                return [];
            }

            return array_values(array_filter(array_map(function (mixed $point): ?array {
                if (! is_array($point) || count($point) < 2 || ! is_numeric($point[0]) || ! is_numeric($point[1])) {
                    return null;
                }

                return [(float) $point[0], (float) $point[1]];
            }, $line)));
        }, $lines), fn (array $line): bool => count($line) >= 2));
    }

    /** @param array<int, array<int, array{0: float, 1: float}>> $lines
     *  @return array<int, array<int, array{0: float, 1: float}>>
     */
    private function stitchLines(array $lines): array
    {
        $endpointIndex = [];
        foreach ($lines as $index => $line) {
            $endpointIndex[$this->pointKey($line[0])][] = $index;
            $endpointIndex[$this->pointKey($line[array_key_last($line)])][] = $index;
        }

        $used = [];
        $stitched = [];
        foreach ($lines as $index => $line) {
            if (isset($used[$index])) {
                continue;
            }

            $used[$index] = true;
            $path = $line;
            $this->extendPath($path, $lines, $endpointIndex, $used, true);
            $this->extendPath($path, $lines, $endpointIndex, $used, false);
            $stitched[] = $path;
        }

        return $stitched;
    }

    /** @param array<int, array{0: float, 1: float}> $path
     *  @param array<int, array<int, array{0: float, 1: float}>> $lines
     *  @param array<string, array<int, int>> $endpointIndex
     *  @param array<int, bool> $used
     */
    private function extendPath(array &$path, array $lines, array $endpointIndex, array &$used, bool $atEnd): void
    {
        while (true) {
            $point = $atEnd ? $path[array_key_last($path)] : $path[0];
            $candidate = null;
            foreach ($endpointIndex[$this->pointKey($point)] ?? [] as $lineIndex) {
                if (! isset($used[$lineIndex])) {
                    $candidate = $lineIndex;
                    break;
                }
            }
            if ($candidate === null) {
                return;
            }

            $used[$candidate] = true;
            $line = $lines[$candidate];
            $firstMatches = $this->pointKey($line[0]) === $this->pointKey($point);

            if ($atEnd) {
                $append = $firstMatches ? $line : array_reverse($line);
                array_push($path, ...array_slice($append, 1));
            } else {
                $prepend = $firstMatches ? array_reverse($line) : $line;
                $path = [...array_slice($prepend, 0, -1), ...$path];
            }
        }
    }

    /** @param array<int, array{0: float, 1: float}> $points
     *  @return array<int, array{0: float, 1: float}>
     */
    private function simplifyLine(array $points, float $tolerance): array
    {
        $count = count($points);
        if ($count <= 2) {
            return array_map($this->roundPoint(...), $points);
        }

        $keep = [0 => true, $count - 1 => true];
        $stack = [[0, $count - 1]];
        $threshold = $tolerance * $tolerance;

        while ($stack !== []) {
            [$start, $end] = array_pop($stack);
            $maximum = 0.0;
            $furthest = null;

            for ($index = $start + 1; $index < $end; $index++) {
                $distance = $this->pointSegmentDistanceSquared($points[$index], $points[$start], $points[$end]);
                if ($distance > $maximum) {
                    $maximum = $distance;
                    $furthest = $index;
                }
            }

            if ($furthest !== null && $maximum > $threshold) {
                $keep[$furthest] = true;
                $stack[] = [$start, $furthest];
                $stack[] = [$furthest, $end];
            }
        }

        $indexes = array_keys($keep);
        sort($indexes, SORT_NUMERIC);

        return array_map(fn (int $index): array => $this->roundPoint($points[$index]), $indexes);
    }

    private function pointSegmentDistanceSquared(array $point, array $start, array $end): float
    {
        $dx = $end[0] - $start[0];
        $dy = $end[1] - $start[1];
        if ($dx === 0.0 && $dy === 0.0) {
            return ($point[0] - $start[0]) ** 2 + ($point[1] - $start[1]) ** 2;
        }

        $ratio = (($point[0] - $start[0]) * $dx + ($point[1] - $start[1]) * $dy) / ($dx * $dx + $dy * $dy);
        $ratio = max(0.0, min(1.0, $ratio));
        $projectedX = $start[0] + $ratio * $dx;
        $projectedY = $start[1] + $ratio * $dy;

        return ($point[0] - $projectedX) ** 2 + ($point[1] - $projectedY) ** 2;
    }

    private function pointKey(array $point): string
    {
        return number_format((float) $point[0], 6, '.', '').','.number_format((float) $point[1], 6, '.', '');
    }

    /** @return array{0: float, 1: float} */
    private function roundPoint(array $point): array
    {
        return [round((float) $point[0], 6), round((float) $point[1], 6)];
    }

    private function plain(string $value): string
    {
        $ascii = Str::ascii(mb_strtoupper(trim($value), 'UTF-8'));

        return trim(preg_replace('/[^A-Z0-9]+/', ' ', $ascii) ?: $value);
    }

    /** @param array<int, array<string, mixed>> $features */
    private function writeGeoJson(string $path, array $features, array $metadata): void
    {
        $payload = [
            'type' => 'FeatureCollection',
            'metadata' => [
                ...$metadata,
                'generated_at' => now()->toIso8601String(),
                'feature_count' => count($features),
                'source_feature_count' => array_sum(array_map(
                    fn (array $feature): int => (int) ($feature['properties']['source_segments'] ?? 0),
                    $features,
                )),
            ],
            'features' => $features,
        ];
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (file_put_contents($path, $json, LOCK_EX) === false) {
            throw new RuntimeException("No se pudo escribir la capa: {$path}");
        }
    }

    private function absolutePath(string $path, bool $mustExist = true): string
    {
        $absolute = preg_match('/^(?:[A-Za-z]:[\\\\\/]|\/)/', $path) === 1 ? $path : base_path($path);

        return $mustExist ? (realpath($absolute) ?: $absolute) : $absolute;
    }
}
