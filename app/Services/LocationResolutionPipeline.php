<?php

namespace App\Services;

use App\DTOs\GeocodingResult;
use App\DTOs\RoadLocationDTO;
use App\Enums\LocationAccuracy;

class LocationResolutionPipeline
{
    public function __construct(private readonly ParaguayRoadLocationParser $parser, private readonly RoadKilometerInterpolator $roads, private readonly RoadIntersectionResolver $intersections) {}

    public function resolve(string $text, ?float $nativeLat = null, ?float $nativeLng = null, ?string $city = null, ?string $district = null, ?string $department = null): ?RoadLocationDTO
    {
        if ($nativeLat !== null && $nativeLng !== null) return new RoadLocationDTO(originalText: $text, department: $department, district: $district, city: $city, latitude: $nativeLat, longitude: $nativeLng, resolutionMethod: 'NATIVE_COORDINATES', accuracy: LocationAccuracy::Exact->value, estimatedErrorMeters: 0, evidence: ['method' => 'NATIVE_COORDINATES']);
        $parsed = $this->parser->parse($text);
        if ($parsed === null) return null;
        if ($parsed->kilometer === null && $parsed->intersectionText !== null) {
            $second = $this->parser->parse($parsed->intersectionText); $firstRoad = $this->roads->road($parsed->roadCode); $secondRoad = $this->roads->road($second?->roadCode);
            if ($firstRoad !== null && $secondRoad !== null && $firstRoad->id !== $secondRoad->id && ($crossing = $this->intersections->resolve($firstRoad, $secondRoad)) !== null) {
                return new RoadLocationDTO(originalText: $text, roadId: $firstRoad->id, roadCode: $firstRoad->code, department: $department, district: $district, city: $city ?: $parsed->city, intersectionText: $parsed->intersectionText, latitude: $crossing['latitude'], longitude: $crossing['longitude'], resolutionMethod: 'ROUTE_INTERSECTION', accuracy: 'route_intersection', estimatedErrorMeters: (int) ceil(max(10, $crossing['distance_meters'])), evidence: [...$parsed->evidence, 'method'=>'ROUTE_INTERSECTION', 'cross_road'=>$secondRoad->code, 'dataset'=>$firstRoad->dataset?->name]);
            }
        }
        return $this->roads->resolve(new RoadLocationDTO(originalText: $parsed->originalText, roadCode: $parsed->roadCode, kilometer: $parsed->kilometer, relativeOffsetM: $parsed->relativeOffsetM, department: $department, district: $district, city: $city ?: $parsed->city, intersectionText: $parsed->intersectionText, directionUnknown: $parsed->directionUnknown, evidence: $parsed->evidence));
    }

    public function geocodingResult(RoadLocationDTO $location): ?GeocodingResult
    {
        if (! $location->hasCoordinates()) return null;
        return new GeocodingResult($location->latitude, $location->longitude, LocationAccuracy::from($location->accuracy), 'road-network', $location->originalText);
    }
}
