<?php

namespace App\Services;

use App\Models\CriticalFacility;

class NearbyInfrastructureService
{
    public function __construct(private readonly GeoDistanceService $distance) {}

    /** @return array<string, mixed> */
    public function summary(float $latitude, float $longitude, float $radiusKm = 2): array
    {
        $box = $this->distance->boundingBox($latitude, $longitude, $radiusKm);
        $facilities = CriticalFacility::query()
            ->whereBetween('latitude', [$box['south'], $box['north']])
            ->whereBetween('longitude', [$box['west'], $box['east']])
            ->get()
            ->map(function (CriticalFacility $facility) use ($latitude, $longitude): CriticalFacility {
                $facility->setAttribute('distance_km', $this->distance->kilometers($latitude, $longitude, $facility->latitude, $facility->longitude));

                return $facility;
            })
            ->filter(fn (CriticalFacility $facility): bool => $facility->distance_km <= $radiusKm)
            ->sortBy('distance_km')
            ->values();

        $labels = ['health' => 'Establecimientos de salud', 'education' => 'Instituciones educativas', 'police' => 'Dependencias policiales'];
        $icons = ['health' => 'health_vector', 'education' => 'education', 'police' => 'security'];

        return [
            'radius_km' => $radiusKm,
            'total' => $facilities->count(),
            'categories' => collect($labels)->map(fn (string $label, string $kind): array => [
                'kind' => $kind,
                'label' => $label,
                'icon' => $icons[$kind],
                'count' => $facilities->where('kind', $kind)->count(),
                'nearest' => $facilities->where('kind', $kind)->take(3)->map(fn (CriticalFacility $facility): array => [
                    'name' => $facility->name,
                    'category' => $facility->category,
                    'distance_km' => round($facility->distance_km, 2),
                    'latitude' => $facility->latitude,
                    'longitude' => $facility->longitude,
                ])->values()->all(),
            ])->values()->all(),
            'source' => [
                'name' => 'Instituto Nacional de Estadística',
                'url' => (string) config('alertapy.infrastructure.source_url'),
            ],
        ];
    }
}
