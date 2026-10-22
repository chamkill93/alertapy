<?php

namespace App\Services;

use App\Models\Road;
use App\Models\RoadAlias;
use App\Models\RoadDataset;
use App\Models\RoadReferencePoint;
use App\Models\RoadSegment;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use ZipArchive;

class RoadDatasetImporter
{
    public function __construct(private readonly GeometryService $geometry, private readonly RoadNameNormalizer $normalizer) {}

    /** @param array{dry_run?:bool,replace?:bool,dataset_name?:string,source_name?:string,source_url?:string,source_date?:string|null} $options */
    public function import(string $path, array $options = []): array
    {
        $real = realpath($path);
        if ($real === false || ! is_file($real) || ! is_readable($real)) throw new RuntimeException('El archivo vial no existe o no puede leerse.');
        $format = strtolower(pathinfo($real, PATHINFO_EXTENSION));
        if (! in_array($format, ['geojson', 'json', 'kml', 'kmz', 'csv'], true)) throw new RuntimeException('Formato no soportado. Usá GeoJSON, KML, KMZ o CSV.');
        $hash = hash_file('sha256', $real);
        if (RoadDataset::query()->where('file_hash', $hash)->exists()) throw new RuntimeException('Este dataset ya fue importado.');
        $features = $this->features($real, $format);
        $preview = $this->validateFeatures($features);
        $result = [...$preview, 'file_hash' => $hash, 'format' => $format, 'dry_run' => (bool) ($options['dry_run'] ?? false)];
        if ($result['valid_roads'] === 0 || ($options['dry_run'] ?? false)) return $result;

        return DB::transaction(function () use ($features, $result, $real, $format, $hash, $options): array {
            $dataset = RoadDataset::query()->create([
                'name' => $options['dataset_name'] ?? pathinfo($real, PATHINFO_FILENAME), 'source_name' => $options['source_name'] ?? 'Dataset público importado',
                'source_url' => $options['source_url'] ?? null, 'source_date' => $options['source_date'] ?? null, 'file_name' => basename($real), 'file_hash' => $hash,
                'format' => $format, 'status' => 'importing', 'metadata_json' => ['invalid_features' => $result['invalid_features']],
            ]);
            $roadsByCode = []; $pendingReferences = []; $importedRoads = 0; $segments = 0; $references = 0;
            foreach ($features as $feature) {
                $geometry = $feature['geometry'] ?? null; $properties = (array) ($feature['properties'] ?? []);
                $code = $this->code($properties);
                if (($geometry['type'] ?? null) === 'Point') { $pendingReferences[] = [$code, $geometry, $properties]; continue; }
                if ($code === null || ! in_array($geometry['type'] ?? null, ['LineString', 'MultiLineString'], true)) continue;
                $lines = array_values(array_filter($geometry['type'] === 'MultiLineString' ? $geometry['coordinates'] : [$geometry['coordinates']], fn ($line) => $this->validLine($line)));
                if ($lines === []) continue;
                $allPoints = array_merge(...$lines); $bbox = $this->geometry->bbox($allPoints); $normalized = $this->normalizer->normalize($code);
                $storedGeometry = count($lines) === 1 ? ['type' => 'LineString', 'coordinates' => $lines[0]] : ['type' => 'MultiLineString', 'coordinates' => $lines];
                $road = Road::query()->create([
                    'source_dataset_id' => $dataset->id, 'code' => $code, 'normalized_code' => $normalized,
                    'name' => $this->property($properties, ['name', 'nombre', 'ruta']), 'official_name' => $this->property($properties, ['official_name', 'nombre_oficial']),
                    'classification' => $this->property($properties, ['classification', 'clasificacion', 'jerarquia']), 'department' => $this->property($properties, ['department', 'departamento']), 'district' => $this->property($properties, ['district', 'distrito']),
                    'geometry_type' => $storedGeometry['type'], 'geometry_json' => $storedGeometry,
                    'bbox_north' => $bbox['north'], 'bbox_south' => $bbox['south'], 'bbox_east' => $bbox['east'], 'bbox_west' => $bbox['west'], 'length_meters' => array_sum(array_map(fn ($line) => $this->geometry->polylineLength($line), $lines)),
                    'direction_start_label' => $this->property($properties, ['direction_start_label', 'inicio']), 'direction_end_label' => $this->property($properties, ['direction_end_label', 'fin']), 'active' => true,
                ]);
                $roadsByCode[$normalized][] = $road; $importedRoads++;
                foreach (array_unique([$code, $normalized, ...$this->aliases($properties)]) as $alias) RoadAlias::query()->firstOrCreate(['road_id' => $road->id, 'normalized_alias' => $this->normalizer->normalize($alias)], ['alias' => $alias, 'source' => $dataset->name]);
                $cumulative = 0.0; $sequence = 0;
                foreach ($lines as $line) for ($i = 1; $i < count($line); $i++) {
                    $a = $line[$i - 1]; $b = $line[$i]; $length = $this->geometry->haversine((float) $a[1], (float) $a[0], (float) $b[1], (float) $b[0]);
                    RoadSegment::query()->create(['road_id' => $road->id, 'sequence' => $sequence++, 'start_lat' => $a[1], 'start_lng' => $a[0], 'end_lat' => $b[1], 'end_lng' => $b[0], 'segment_length_meters' => $length, 'cumulative_start_meters' => $cumulative, 'cumulative_end_meters' => $cumulative + $length, 'bbox_north' => max($a[1], $b[1]), 'bbox_south' => min($a[1], $b[1]), 'bbox_east' => max($a[0], $b[0]), 'bbox_west' => min($a[0], $b[0]), 'department' => $road->department, 'district' => $road->district, 'city' => $this->property($properties, ['city', 'ciudad', 'localidad'])]);
                    $cumulative += $length; $segments++;
                }
            }
            foreach ($pendingReferences as [$code, $geometry, $properties]) {
                $normalized = $code ? $this->normalizer->normalize($code) : null; $road = $normalized ? ($roadsByCode[$normalized][0] ?? Road::query()->active()->where('normalized_code', $normalized)->first()) : null;
                $km = $this->numericProperty($properties, ['kilometer', 'kilometro', 'km', 'progresiva']); $point = $geometry['coordinates'] ?? [];
                if ($road === null || count($point) < 2) continue;
                RoadReferencePoint::query()->create(['road_id' => $road->id, 'source_dataset_id' => $dataset->id, 'reference_type' => strtoupper((string) ($this->property($properties, ['reference_type', 'tipo']) ?? ($km !== null ? 'KM_MARKER' : 'OTHER'))), 'kilometer' => $km, 'latitude' => $point[1], 'longitude' => $point[0], 'name' => $this->property($properties, ['name', 'nombre']), 'source_url' => $options['source_url'] ?? null, 'accuracy' => $this->property($properties, ['accuracy', 'precision'])]); $references++;
            }
            if ($options['replace'] ?? false) RoadDataset::query()->whereKeyNot($dataset->id)->where('status', 'imported')->each(function (RoadDataset $old): void { $old->roads()->update(['active' => false]); $old->update(['status' => 'superseded']); });
            $dataset->update(['status' => 'imported', 'imported_at' => now(), 'feature_count' => count($features), 'metadata_json' => ['roads' => $importedRoads, 'segments' => $segments, 'references' => $references, 'invalid_features' => $result['invalid_features']]]);
            return [...$result, 'dataset_id' => $dataset->id, 'roads_imported' => $importedRoads, 'segments_imported' => $segments, 'references_imported' => $references];
        });
    }

    private function features(string $path, string $format): array
    {
        if (in_array($format, ['geojson', 'json'], true)) {
            $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            if (isset($data['elements']) && is_array($data['elements'])) return $this->overpassFeatures($data['elements']);
            return ($data['type'] ?? null) === 'FeatureCollection' ? ($data['features'] ?? []) : [$data];
        }
        if ($format === 'csv') return $this->csvFeatures($path);
        if ($format === 'kmz') {
            if (! class_exists(ZipArchive::class)) throw new RuntimeException('La extensión ZIP de PHP es necesaria para KMZ.');
            $zip = new ZipArchive; if ($zip->open($path) !== true) throw new RuntimeException('KMZ inválido.');
            $kml = null; for ($i = 0; $i < $zip->numFiles; $i++) if (str_ends_with(strtolower($zip->getNameIndex($i)), '.kml')) { $kml = $zip->getFromIndex($i); break; } $zip->close();
            if (! is_string($kml)) throw new RuntimeException('El KMZ no contiene KML.'); return $this->kmlFeatures($kml);
        }
        return $this->kmlFeatures((string) file_get_contents($path));
    }

    private function csvFeatures(string $path): array
    {
        $handle = fopen($path, 'rb'); if ($handle === false) return []; $headers = fgetcsv($handle) ?: []; $features = [];
        while (($row = fgetcsv($handle)) !== false) { $item = array_combine($headers, array_pad($row, count($headers), null)); if (! is_array($item)) continue; $geometry = isset($item['geometry_json']) ? json_decode((string) $item['geometry_json'], true) : null; if (! is_array($geometry) && isset($item['latitude'], $item['longitude'])) $geometry = ['type' => 'Point', 'coordinates' => [(float) $item['longitude'], (float) $item['latitude']]]; if ($geometry) $features[] = ['type' => 'Feature', 'properties' => $item, 'geometry' => $geometry]; }
        fclose($handle); return $features;
    }

    /** @param array<int, array<string, mixed>> $elements */
    private function overpassFeatures(array $elements): array
    {
        $features = [];
        foreach ($elements as $element) {
            $tags = (array) ($element['tags'] ?? []); $geometry = (array) ($element['geometry'] ?? []);
            $ref = trim((string) ($tags['ref'] ?? ''));
            if (preg_match('/^PY\s*-?\s*0?\d{1,2}$/i', $ref) !== 1) continue;
            if (($element['type'] ?? null) === 'relation' && ($tags['network'] ?? null) !== 'PY:national') continue;
            $properties = ['code' => $this->normalizer->normalize($ref), 'name' => $tags['name'] ?? null, 'official_name' => $tags['official_name'] ?? null, 'classification' => 'nacional', 'source_id' => $element['id'] ?? null];
            if (($element['type'] ?? null) === 'relation') {
                $lines = [];
                foreach ((array) ($element['members'] ?? []) as $member) {
                    $points = array_values(array_filter(array_map(fn ($point) => isset($point['lat'], $point['lon']) ? [(float) $point['lon'], (float) $point['lat']] : null, (array) ($member['geometry'] ?? []))));
                    if (count($points) >= 2) $lines[] = $points;
                }
                if ($lines !== []) $features[] = ['type' => 'Feature', 'properties' => $properties, 'geometry' => ['type' => 'MultiLineString', 'coordinates' => $lines]];
                continue;
            }
            if (($element['type'] ?? null) !== 'way' || count($geometry) < 2) continue;
            $coordinates = array_values(array_filter(array_map(fn ($point) => isset($point['lat'], $point['lon']) ? [(float) $point['lon'], (float) $point['lat']] : null, $geometry)));
            if (count($coordinates) >= 2) $features[] = ['type' => 'Feature', 'properties' => $properties, 'geometry' => ['type' => 'LineString', 'coordinates' => $coordinates]];
        }
        return $features;
    }

    private function kmlFeatures(string $xml): array
    {
        $document = new DOMDocument; if (! @$document->loadXML($xml, LIBXML_NONET)) throw new RuntimeException('KML inválido.'); $xpath = new DOMXPath($document); $features = [];
        foreach ($xpath->query('//*[local-name()="Placemark"]') ?: [] as $placemark) {
            $properties = ['name' => trim((string) $xpath->evaluate('string(.//*[local-name()="name"][1])', $placemark))];
            foreach ($xpath->query('.//*[local-name()="Data"]', $placemark) ?: [] as $data) $properties[$data->attributes?->getNamedItem('name')?->nodeValue ?? ''] = trim((string) $xpath->evaluate('string(.//*[local-name()="value"][1])', $data));
            $coordinates = trim((string) $xpath->evaluate('string(.//*[local-name()="coordinates"][1])', $placemark)); if ($coordinates === '') continue;
            $points = array_values(array_filter(array_map(function ($part) { $xyz = array_map('floatval', explode(',', trim($part))); return count($xyz) >= 2 ? [$xyz[0], $xyz[1]] : null; }, preg_split('/\s+/', $coordinates) ?: [])));
            $type = $xpath->evaluate('boolean(.//*[local-name()="Point"])', $placemark) ? 'Point' : 'LineString'; $features[] = ['type' => 'Feature', 'properties' => $properties, 'geometry' => ['type' => $type, 'coordinates' => $type === 'Point' ? ($points[0] ?? []) : $points]];
        }
        return $features;
    }

    private function validateFeatures(array $features): array
    {
        $roads = 0; $references = 0; $invalid = 0; $codes = []; foreach ($features as $feature) { $geometry = $feature['geometry'] ?? []; $type = $geometry['type'] ?? null; $code = $this->code((array) ($feature['properties'] ?? [])); if (in_array($type, ['LineString', 'MultiLineString'], true) && $code !== null) { $roads++; $codes[] = $this->normalizer->normalize($code); } elseif ($type === 'Point') $references++; else $invalid++; }
        $activeCodes = Road::query()->active()->pluck('normalized_code')->unique(); $incomingCodes = collect($codes)->unique();
        return ['feature_count' => count($features), 'valid_roads' => $roads, 'reference_points' => $references, 'invalid_features' => $invalid, 'roads_new' => $incomingCodes->diff($activeCodes)->count(), 'roads_modified' => $incomingCodes->intersect($activeCodes)->count(), 'roads_removed' => $activeCodes->diff($incomingCodes)->count()];
    }

    private function validLine(mixed $line): bool { return is_array($line) && count($line) >= 2 && collect($line)->every(fn ($p) => is_array($p) && count($p) >= 2 && is_numeric($p[0]) && is_numeric($p[1]) && $p[1] >= -27.8 && $p[1] <= -19 && $p[0] >= -63 && $p[0] <= -54); }
    private function code(array $properties): ?string { $value = $this->property($properties, ['code', 'codigo', 'route_code', 'ruta_codigo', 'ruta', 'ref']); return $value !== null && $this->normalizer->normalize($value) !== '' ? $this->normalizer->normalize($value) : null; }
    private function property(array $properties, array $keys): ?string { foreach ($keys as $key) foreach ($properties as $actual => $value) if (strtolower((string) $actual) === $key && is_scalar($value) && trim((string) $value) !== '') return trim((string) $value); return null; }
    private function numericProperty(array $properties, array $keys): ?float { $value = $this->property($properties, $keys); return $value !== null && is_numeric(str_replace(',', '.', $value)) ? (float) str_replace(',', '.', $value) : null; }
    private function aliases(array $properties): array { $value = $this->property($properties, ['aliases', 'alias']); return $value ? array_values(array_filter(array_map('trim', preg_split('/[,;|]/', $value) ?: []))) : []; }
}
