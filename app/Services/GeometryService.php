<?php

namespace App\Services;

class GeometryService
{
    private const EARTH_RADIUS_M = 6371008.8;

    public function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1); $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return self::EARTH_RADIUS_M * 2 * atan2(sqrt($a), sqrt(max(0, 1 - $a)));
    }

    /** @param array<int, array{0: float|int, 1: float|int}> $coordinates */
    public function polylineLength(array $coordinates): float
    {
        $length = 0.0;
        for ($i = 1; $i < count($coordinates); $i++) $length += $this->haversine((float) $coordinates[$i - 1][1], (float) $coordinates[$i - 1][0], (float) $coordinates[$i][1], (float) $coordinates[$i][0]);
        return $length;
    }

    /** @param array<int, array{0: float|int, 1: float|int}> $coordinates @return array{south: float,west: float,north: float,east: float} */
    public function bbox(array $coordinates): array
    {
        $lngs = array_map(fn ($p) => (float) $p[0], $coordinates); $lats = array_map(fn ($p) => (float) $p[1], $coordinates);
        return ['south' => min($lats), 'west' => min($lngs), 'north' => max($lats), 'east' => max($lngs)];
    }

    /** @param array<int, array{0: float|int, 1: float|int}> $ring */
    public function pointInPolygon(float $lat, float $lng, array $ring): bool
    {
        $inside = false; $count = count($ring);
        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            [$xi, $yi] = $ring[$i]; [$xj, $yj] = $ring[$j];
            if (((float) $yi > $lat) !== ((float) $yj > $lat) && $lng < (((float) $xj - (float) $xi) * ($lat - (float) $yi) / (((float) $yj - (float) $yi) ?: 1e-12) + (float) $xi)) $inside = ! $inside;
        }
        return $inside;
    }

    /** @return array{latitude: float,longitude: float,distance_meters: float,t: float} */
    public function closestPointOnSegment(float $lat, float $lng, float $startLat, float $startLng, float $endLat, float $endLng): array
    {
        $scale = max(.1, cos(deg2rad(($startLat + $endLat + $lat) / 3)));
        $px = $lng * $scale; $py = $lat; $ax = $startLng * $scale; $ay = $startLat; $bx = $endLng * $scale; $by = $endLat;
        $denominator = (($bx - $ax) ** 2 + ($by - $ay) ** 2);
        $t = $denominator > 0 ? max(0, min(1, (($px - $ax) * ($bx - $ax) + ($py - $ay) * ($by - $ay)) / $denominator)) : 0;
        $closestLat = $startLat + ($endLat - $startLat) * $t; $closestLng = $startLng + ($endLng - $startLng) * $t;
        return ['latitude' => $closestLat, 'longitude' => $closestLng, 'distance_meters' => $this->haversine($lat, $lng, $closestLat, $closestLng), 't' => $t];
    }

    /** @return array{latitude: float,longitude: float}|null */
    public function segmentIntersection(float $aLat, float $aLng, float $bLat, float $bLng, float $cLat, float $cLng, float $dLat, float $dLng): ?array
    {
        $denominator = (($aLng - $bLng) * ($cLat - $dLat)) - (($aLat - $bLat) * ($cLng - $dLng));
        if (abs($denominator) < 1e-12) return null;

        $detA = ($aLng * $bLat) - ($aLat * $bLng);
        $detB = ($cLng * $dLat) - ($cLat * $dLng);
        $lng = (($detA * ($cLng - $dLng)) - (($aLng - $bLng) * $detB)) / $denominator;
        $lat = (($detA * ($cLat - $dLat)) - (($aLat - $bLat) * $detB)) / $denominator;
        $epsilon = 1e-9;
        if ($lng < min($aLng, $bLng) - $epsilon || $lng > max($aLng, $bLng) + $epsilon
            || $lat < min($aLat, $bLat) - $epsilon || $lat > max($aLat, $bLat) + $epsilon
            || $lng < min($cLng, $dLng) - $epsilon || $lng > max($cLng, $dLng) + $epsilon
            || $lat < min($cLat, $dLat) - $epsilon || $lat > max($cLat, $dLat) + $epsilon) return null;

        return ['latitude' => $lat, 'longitude' => $lng];
    }

    /** @param array<int, array{0: float|int, 1: float|int}> $line @return array{latitude: float,longitude: float,distance_meters: float,offset_meters: float} */
    public function closestPointOnLine(float $lat, float $lng, array $line): array
    {
        $best = ['latitude' => $lat, 'longitude' => $lng, 'distance_meters' => INF, 'offset_meters' => 0.0]; $offset = 0.0;
        for ($i = 1; $i < count($line); $i++) {
            $a = $line[$i - 1]; $b = $line[$i]; $segmentLength = $this->haversine((float) $a[1], (float) $a[0], (float) $b[1], (float) $b[0]);
            $point = $this->closestPointOnSegment($lat, $lng, (float) $a[1], (float) $a[0], (float) $b[1], (float) $b[0]);
            if ($point['distance_meters'] < $best['distance_meters']) $best = [...$point, 'offset_meters' => $offset + $segmentLength * $point['t']];
            $offset += $segmentLength;
        }
        return $best;
    }

    /** @param array<int, array{0: float|int, 1: float|int}> $line @return array{latitude: float,longitude: float} */
    public function interpolateAlongLine(array $line, float $offsetMeters): array
    {
        $remaining = max(0, $offsetMeters);
        for ($i = 1; $i < count($line); $i++) {
            $a = $line[$i - 1]; $b = $line[$i]; $length = $this->haversine((float) $a[1], (float) $a[0], (float) $b[1], (float) $b[0]);
            if ($remaining <= $length || $i === count($line) - 1) {
                $t = $length > 0 ? min(1, $remaining / $length) : 0;
                return ['latitude' => (float) $a[1] + ((float) $b[1] - (float) $a[1]) * $t, 'longitude' => (float) $a[0] + ((float) $b[0] - (float) $a[0]) * $t];
            }
            $remaining -= $length;
        }
        return ['latitude' => (float) ($line[0][1] ?? 0), 'longitude' => (float) ($line[0][0] ?? 0)];
    }

    public function boundingBoxAround(float $lat, float $lng, float $radiusKm): array
    {
        $latDelta = $radiusKm / 111.32; $lngDelta = $radiusKm / max(1, 111.32 * cos(deg2rad($lat)));
        return ['south' => $lat - $latDelta, 'west' => $lng - $lngDelta, 'north' => $lat + $latDelta, 'east' => $lng + $lngDelta];
    }
}
