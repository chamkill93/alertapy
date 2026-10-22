<?php

namespace App\Services;

class GeoDistanceService
{
    public function kilometers(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $latDelta = deg2rad($lat2 - $lat1);
        $lngDelta = deg2rad($lng2 - $lng1);
        $a = sin($latDelta / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($lngDelta / 2) ** 2;

        return 6371 * 2 * asin(min(1, sqrt($a)));
    }

    /** @return array{south: float, north: float, west: float, east: float} */
    public function boundingBox(float $latitude, float $longitude, float $radiusKm): array
    {
        $latDelta = $radiusKm / 111;
        $lngDelta = $radiusKm / max(1, 111 * cos(deg2rad($latitude)));

        return ['south' => $latitude - $latDelta, 'north' => $latitude + $latDelta, 'west' => $longitude - $lngDelta, 'east' => $longitude + $lngDelta];
    }
}
