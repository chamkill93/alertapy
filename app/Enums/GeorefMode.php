<?php

namespace App\Enums;

enum GeorefMode: string
{
    case NativeCoordinates = 'native_coordinates';
    case ExtractAndGeocode = 'extract_and_geocode';
    case Territorial = 'territorial';
    case Station = 'station';
    case Route = 'route';
    case Polygon = 'polygon';
    case None = 'none';
}
