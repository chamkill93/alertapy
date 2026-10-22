<?php

namespace App\Collectors;

use App\Models\MonitoringStation;
use App\Models\Source;
use App\Models\StationMeasurement;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class MadesAirQualityCollector
{
    /** @return array{stations: int, measurements: int} */
    public function sync(Source $source): array
    {
        $apiKey = trim((string) config('alertapy.sources.mades-air-quality.api_key'));

        if ($apiKey === '') {
            throw new RuntimeException('Falta configurar MADES_AIR_API_KEY.');
        }

        $baseUrl = rtrim((string) config('alertapy.sources.mades-air-quality.api_url'), '/');
        $request = $this->request($apiKey);
        $response = $request->get($baseUrl.'/stations');

        if (! $response->successful()) {
            throw new RuntimeException("MADES Calidad del Aire respondió HTTP {$response->status()}.");
        }

        $stations = collect($response->json('data'))->filter(fn (mixed $item): bool => is_array($item));
        $measurementCount = 0;

        foreach ($stations as $item) {
            if (! is_numeric($item['latitude'] ?? null) || ! is_numeric($item['longitude'] ?? null)) {
                continue;
            }

            $station = MonitoringStation::query()->updateOrCreate(
                ['source_id' => $source->id, 'external_id' => (string) $item['id']],
                [
                    'name' => (string) ($item['name'] ?? $item['station_code'] ?? 'Estación MADES'),
                    'kind' => 'air_quality',
                    'city' => $item['city'] ?? null,
                    'department' => $item['department'] ?? null,
                    'latitude' => (float) $item['latitude'],
                    'longitude' => (float) $item['longitude'],
                    'is_active' => (bool) ($item['is_active'] ?? true),
                    'is_collecting_data' => (bool) ($item['is_collecting_data'] ?? false),
                    'is_under_maintenance' => (bool) ($item['is_under_maintenance'] ?? false),
                    'metadata' => ['station_code' => $item['station_code'] ?? null, 'station_type' => $item['type'] ?? null],
                ],
            );

            if (! $station->is_active || ! $station->is_collecting_data) {
                continue;
            }

            $from = CarbonImmutable::now((string) config('app.timezone'))->subDay()->toDateString();
            $to = CarbonImmutable::now((string) config('app.timezone'))->toDateString();
            $dataResponse = $request->get($baseUrl."/stations/{$item['id']}/data", [
                'date_from' => $from,
                'date_to' => $to,
                'per_page' => 100,
            ]);

            if (! $dataResponse->successful()) {
                continue;
            }

            foreach ((array) $dataResponse->json('data', []) as $reading) {
                if (! is_array($reading) || ! is_numeric($reading['parameter_value'] ?? null)) {
                    continue;
                }

                if (! is_string($reading['date_time'] ?? null) || trim($reading['date_time']) === '') {
                    continue;
                }

                try {
                    $measuredAt = CarbonImmutable::parse($reading['date_time']);
                } catch (\Throwable) {
                    continue;
                }
                $measurement = StationMeasurement::query()->firstOrCreate(
                    [
                        'monitoring_station_id' => $station->id,
                        'parameter' => (string) $reading['parameter_name'],
                        'measured_at' => $measuredAt,
                    ],
                    [
                        'value' => (float) $reading['parameter_value'],
                        'unit' => $reading['unit'] ?? null,
                        'metadata' => ['official_api' => true],
                    ],
                );
                $measurementCount += (int) $measurement->wasRecentlyCreated;

                if ($station->last_observed_at === null || $measuredAt->gt($station->last_observed_at)) {
                    $station->forceFill(['last_observed_at' => $measuredAt])->save();
                }
            }
        }

        return ['stations' => $stations->count(), 'measurements' => $measurementCount];
    }

    private function request(string $apiKey): PendingRequest
    {
        return Http::acceptJson()
            ->withHeaders(['X-Api-Key' => $apiKey])
            ->withUserAgent((string) config('alertapy.http.user_agent'))
            ->timeout((int) config('alertapy.http.timeout', 15))
            ->retry(2, 300, throw: false);
    }
}
