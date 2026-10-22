<?php

namespace App\Enums;

enum LocationMethod: string
{
    case SourceCoordinates = 'source_coordinates';
    case RouteKmOfficial = 'route_km_official';
    case RouteKmInterpolated = 'route_km_interpolated';
    case RouteIntersection = 'route_intersection';
    case RouteLocality = 'route_locality';
    case StreetIntersection = 'street_intersection';
    case Neighborhood = 'neighborhood';
    case City = 'city';
    case District = 'district';
    case Department = 'department';
    case Area = 'area';
    case Unknown = 'unknown';
}
