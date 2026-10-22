<?php

namespace App\Enums;

enum LocationAccuracy: string
{
    case Exact = 'exact';
    case Street = 'street';
    case Neighborhood = 'neighborhood';
    case City = 'city';
    case District = 'district';
    case Department = 'department';
    case Approximate = 'approximate';
    case RouteKmOfficial = 'route_km_official';
    case RouteKmObserved = 'route_km_observed';
    case RouteKmInterpolated = 'route_km_interpolated';
    case RouteIntersection = 'route_intersection';
    case RouteLocality = 'route_locality';
    case RouteOnly = 'route_only';

    public function label(): string
    {
        return match ($this) {
            self::Exact => 'Ubicación exacta',
            self::Street => 'A nivel de calle',
            self::Neighborhood => 'A nivel de barrio',
            self::City => 'A nivel de ciudad',
            self::District => 'A nivel de distrito',
            self::Department => 'A nivel de departamento',
            self::Approximate => 'Ubicación aproximada',
            self::RouteKmOfficial => 'Kilometraje vial oficial',
            self::RouteKmObserved => 'Hito kilométrico cartografiado',
            self::RouteKmInterpolated => 'Kilometraje vial interpolado',
            self::RouteIntersection => 'Intersección vial',
            self::RouteLocality => 'Tramo vial en la localidad',
            self::RouteOnly => 'Ruta identificada sin punto preciso',
        };
    }

    public function isApproximate(): bool
    {
        return ! in_array($this, [self::Exact, self::Street, self::RouteKmOfficial], true);
    }
}
