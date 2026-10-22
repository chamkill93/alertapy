<?php

namespace App\Collectors;

use App\Contracts\Collectors\CollectorInterface;
use App\DTOs\CandidateDTO;
use App\Enums\SourceType;
use App\Models\Source;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class NasaFirmsCollector implements CollectorInterface
{
    /** @return Collection<int, CandidateDTO> */
    public function collect(Source $source): Collection
    {
        $key = trim((string) config('alertapy.sources.nasa-firms.map_key'));

        if ($key === '') {
            throw new RuntimeException('Falta configurar NASA_FIRMS_MAP_KEY.');
        }

        $settings = (array) config('alertapy.sources.nasa-firms');
        $bbox = (string) ($settings['bbox'] ?? '-62.7,-27.7,-54.2,-19.1');
        $days = max(1, min(5, (int) ($settings['day_range'] ?? 2)));
        $sensor = rawurlencode((string) ($settings['source'] ?? 'VIIRS_NOAA20_NRT'));
        $url = rtrim((string) $settings['api_url'], '/').'/'.rawurlencode($key)."/{$sensor}/{$bbox}/{$days}";
        $response = Http::accept('text/csv')
            ->withUserAgent((string) config('alertapy.http.user_agent'))
            ->timeout((int) config('alertapy.http.timeout', 15))
            ->retry(2, 300, throw: false)
            ->get($url);

        if (! $response->successful()) {
            throw new RuntimeException("NASA FIRMS respondió HTTP {$response->status()}.");
        }

        $rows = preg_split('/\R/u', trim($response->body())) ?: [];
        $header = array_map('trim', str_getcsv((string) array_shift($rows)));
        $bounds = array_map('floatval', explode(',', $bbox));

        if (count($header) < 2 || count($bounds) !== 4) {
            throw new RuntimeException('NASA FIRMS devolvió un CSV o configuración geográfica inválida.');
        }

        return collect($rows)->map(function (string $line) use ($header, $source, $bounds): ?CandidateDTO {
            $values = str_getcsv($line);

            if (count($values) !== count($header)) {
                return null;
            }

            $item = array_combine($header, $values);
            $latitude = filter_var($item['latitude'] ?? null, FILTER_VALIDATE_FLOAT);
            $longitude = filter_var($item['longitude'] ?? null, FILTER_VALIDATE_FLOAT);

            if ($latitude === false || $longitude === false || ! $this->insideBounds($latitude, $longitude, $bounds)) {
                return null;
            }

            try {
                $time = str_pad(preg_replace('/\D/', '', (string) ($item['acq_time'] ?? '')) ?: '', 4, '0', STR_PAD_LEFT);
                $observedAt = CarbonImmutable::createFromFormat('Y-m-d Hi', ($item['acq_date'] ?? '').' '.$time, 'UTC');
            } catch (Throwable) {
                return null;
            }

            $sensor = trim((string) ($item['instrument'] ?? $item['satellite'] ?? 'satélite'));
            $confidence = trim((string) ($item['confidence'] ?? 'no informada'));
            $brightness = trim((string) ($item['bright_ti4'] ?? $item['brightness'] ?? ''));
            $identifier = hash('sha256', implode('|', [$latitude, $longitude, $observedAt->toIso8601String(), $sensor]));
            $coordinates = sprintf('%.5f, %.5f', $latitude, $longitude);

            return new CandidateDTO(
                sourceSlug: $source->slug,
                sourceType: SourceType::Sensor,
                externalIdentifier: $identifier,
                title: 'Detección térmica satelital',
                originalUrl: 'https://firms.modaps.eosdis.nasa.gov/map/',
                content: "Detección térmica remota registrada por {$sensor}. Confianza informada: {$confidence}. No constituye por sí sola un incendio confirmado.",
                publishedAt: $observedAt->setTimezone((string) config('app.timezone')),
                detectedLocation: $coordinates,
                latitude: (float) $latitude,
                longitude: (float) $longitude,
                metadata: [
                    'category_slug' => 'fire',
                    'subtype' => 'thermal_detection',
                    'location_text' => "Coordenadas satelitales {$coordinates}",
                    'location_accuracy' => 'exact',
                    'sensor' => $sensor,
                    'confidence' => $confidence,
                    'brightness' => $brightness,
                    'starts_at' => $observedAt->toIso8601String(),
                    'original_title' => 'Detección térmica satelital NASA FIRMS',
                ],
            );
        })->filter()->values();
    }

    /** @param array<int, float> $bounds */
    private function insideBounds(float $latitude, float $longitude, array $bounds): bool
    {
        [$west, $south, $east, $north] = $bounds;

        return $longitude >= $west && $longitude <= $east && $latitude >= $south && $latitude <= $north;
    }
}
