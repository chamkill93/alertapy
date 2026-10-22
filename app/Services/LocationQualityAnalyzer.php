<?php

namespace App\Services;

use App\Enums\LocationAccuracy;
use App\Enums\LocationMethod;
use App\Enums\LocationQuality;
use App\Models\Incident;
use App\Models\Location;

class LocationQualityAnalyzer
{
    /** @return array{method: LocationMethod, quality: LocationQuality, flags: array<int, string>} */
    public function analyze(Incident $incident): array
    {
        $method = $this->method($incident);
        $quality = match ($method) {
            LocationMethod::SourceCoordinates, LocationMethod::RouteKmOfficial => LocationQuality::VeryHigh,
            LocationMethod::RouteKmInterpolated, LocationMethod::RouteIntersection, LocationMethod::StreetIntersection => LocationQuality::High,
            LocationMethod::RouteLocality, LocationMethod::Neighborhood => LocationQuality::Medium,
            LocationMethod::City, LocationMethod::District => LocationQuality::Low,
            LocationMethod::Department => LocationQuality::VeryLow,
            default => LocationQuality::Unknown,
        };
        $flags = [];
        $lat = $incident->latitude; $lng = $incident->longitude;
        if ($lat === null || $lng === null) $flags[] = 'UNKNOWN_LOCATION';
        elseif ($lat == 0.0 || $lng == 0.0) $flags[] = 'ZERO_COORDINATES';
        elseif ($lat < -27.8 || $lat > -19.0 || $lng < -63.0 || $lng > -54.0) $flags[] = 'OUTSIDE_PARAGUAY';
        if (in_array($quality, [LocationQuality::Low, LocationQuality::VeryLow, LocationQuality::Unknown], true)) $flags[] = 'LOW_ACCURACY';
        if (in_array($method, [LocationMethod::City, LocationMethod::District, LocationMethod::Department], true)) $flags[] = 'CENTROID_ONLY';
        if ($incident->road !== null && $incident->road_kilometer !== null && $incident->road->length_meters > 0 && $incident->road_kilometer > ($incident->road->length_meters / 1000) + 5) $flags[] = 'ROUTE_KM_OUT_OF_RANGE';
        if ($incident->city && $incident->department) {
            $knownMismatch = Location::query()->where('name', $incident->city)->whereNotNull('department')->where('department', '!=', $incident->department)->exists()
                && ! Location::query()->where('name', $incident->city)->where('department', $incident->department)->exists();
            if ($knownMismatch) $flags[] = 'CITY_DEPARTMENT_MISMATCH';
        }
        return ['method' => $method, 'quality' => $quality, 'flags' => array_values(array_unique($flags))];
    }

    private function method(Incident $incident): LocationMethod
    {
        $road = strtoupper((string) $incident->road_location_method);
        if ($road === 'NATIVE_COORDINATES') return LocationMethod::SourceCoordinates;
        if (in_array($road, ['ROUTE_KM_OFFICIAL', 'OFFICIAL'], true)) return LocationMethod::RouteKmOfficial;
        if (str_contains($road, 'INTERPOLAT')) return LocationMethod::RouteKmInterpolated;
        if ($road === 'ROUTE_INTERSECTION') return LocationMethod::RouteIntersection;
        if (in_array($road, ['ROUTE_LOCALITY', 'ROUTE_ONLY'], true)) return LocationMethod::RouteLocality;
        return match ($incident->location_accuracy) {
            LocationAccuracy::Exact => LocationMethod::SourceCoordinates,
            LocationAccuracy::Street => LocationMethod::StreetIntersection,
            LocationAccuracy::Neighborhood => LocationMethod::Neighborhood,
            LocationAccuracy::City => LocationMethod::City,
            LocationAccuracy::District => LocationMethod::District,
            LocationAccuracy::Department => LocationMethod::Department,
            LocationAccuracy::RouteKmOfficial => LocationMethod::RouteKmOfficial,
            LocationAccuracy::RouteKmInterpolated, LocationAccuracy::RouteKmObserved => LocationMethod::RouteKmInterpolated,
            LocationAccuracy::RouteIntersection => LocationMethod::RouteIntersection,
            LocationAccuracy::RouteLocality, LocationAccuracy::RouteOnly => LocationMethod::RouteLocality,
            default => LocationMethod::Unknown,
        };
    }
}
