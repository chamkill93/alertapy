<?php

namespace App\Collectors;

use App\Models\MonitoringStation;
use App\Models\Source;
use App\Models\StationMeasurement;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

class DmhAutomaticStationCollector
{
    /** @return array{stations: int, measurements: int} */
    public function sync(Source $source): array
    {
        $response = Http::acceptJson()->timeout(60)->retry(2, 500)->get((string) config('alertapy.sources.dmh-automatic-stations.feed_url'));
        $response->throw();
        $stations = $response->json('estaciones', []);
        $stationCount = 0;
        $measurementCount = 0;

        foreach ($stations as $externalId => $payload) {
            $metadata = $payload['metadatos'] ?? [];
            $observation = $payload['ultima_observacion'] ?? [];
            $latitude = $metadata['latitud'] ?? null;
            $longitude = $metadata['longitud'] ?? null;
            if (! is_numeric($latitude) || ! is_numeric($longitude)) continue;

            $observedAt = isset($observation['fecha']['utc']) ? CarbonImmutable::parse($observation['fecha']['utc']) : null;
            $fresh = $observedAt?->gte(now()->subHours(6)) ?? false;
            $station = MonitoringStation::query()->updateOrCreate(
                ['source_id' => $source->id, 'external_id' => (string) $externalId],
                [
                    'name' => trim((string) ($metadata['nombre'] ?? $externalId)), 'kind' => 'weather',
                    'city' => $metadata['ciudad'] ?? null, 'department' => $metadata['departamento'] ?? null,
                    'latitude' => (float) $latitude, 'longitude' => (float) $longitude,
                    'is_active' => ($metadata['estado'] ?? '') === 'activo',
                    'is_collecting_data' => ($payload['tiene_datos'] ?? '') === 'si' && $fresh,
                    'is_under_maintenance' => ! $fresh,
                    'last_observed_at' => $observedAt,
                    'metadata' => ['wmo_code' => $metadata['codigo_omm'] ?? null, 'network' => $metadata['red'] ?? null, 'transmission' => $metadata['estado_transmision'] ?? null],
                ],
            );
            $stationCount++;
            if ($observedAt === null) continue;

            $parameters = [
                'temperature' => 'temperatura_aire', 'humidity' => 'humedad_relativa', 'pressure_qnh' => 'presion_qnh',
                'precipitation' => 'precipitacion', 'wind_direction' => 'direccion_viento', 'wind_speed' => 'velocidad_viento',
                'uv_index' => 'indice_uv', 'radiation' => 'radiacion',
            ];
            foreach ($parameters as $parameter => $sourceKey) {
                $reading = $observation[$sourceKey] ?? null;
                if (! is_array($reading) || ! is_numeric($reading['valor'] ?? null)) continue;
                $measurement = StationMeasurement::query()->firstOrCreate(
                    ['monitoring_station_id' => $station->id, 'parameter' => $parameter, 'measured_at' => $observedAt],
                    ['value' => (float) $reading['valor'], 'unit' => $reading['unidad_medida'] ?? null, 'metadata' => isset($reading['texto']) ? ['direction_text' => $reading['texto']] : null],
                );
                if ($measurement->wasRecentlyCreated) $measurementCount++;
            }
        }
        return ['stations' => $stationCount, 'measurements' => $measurementCount];
    }
}
