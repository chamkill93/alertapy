<?php

namespace App\Services;

use App\Models\Road;

class RoadIntersectionResolver
{
    public function __construct(private readonly GeometryService $geometry) {}

    /** @return array{latitude: float,longitude: float,distance_meters: float}|null */
    public function resolve(Road $first, Road $second, ?float $toleranceMeters = null): ?array
    {
        $tolerance = $toleranceMeters ?? (float) config('alertapy.roads.intersection_tolerance_meters', 80);
        $a = $first->segments()->get(); $b = $second->segments()->get(); $best = null;
        foreach ($a as $one) foreach ($b as $two) {
            $intersection = $this->geometry->segmentIntersection($one->start_lat, $one->start_lng, $one->end_lat, $one->end_lng, $two->start_lat, $two->start_lng, $two->end_lat, $two->end_lng);
            if ($intersection !== null) return [...$intersection, 'distance_meters' => 0.0];
            foreach ([[[$one->start_lat, $one->start_lng], $two], [[$one->end_lat, $one->end_lng], $two], [[$two->start_lat, $two->start_lng], $one], [[$two->end_lat, $two->end_lng], $one]] as [[$lat, $lng], $target]) {
                $candidate = $this->geometry->closestPointOnSegment($lat, $lng, $target->start_lat, $target->start_lng, $target->end_lat, $target->end_lng);
                if ($best === null || $candidate['distance_meters'] < $best['distance_meters']) $best = $candidate;
            }
        }
        return $best !== null && $best['distance_meters'] <= $tolerance ? ['latitude' => $best['latitude'], 'longitude' => $best['longitude'], 'distance_meters' => $best['distance_meters']] : null;
    }
}
