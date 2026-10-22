<?php

namespace App\Enums;

enum IncidentGeometryType: string
{
    case Point = 'point';
    case Line = 'line';
    case Polygon = 'polygon';
    case MultiPolygon = 'multipolygon';
    case RouteSegment = 'route_segment';
    case AreaReference = 'area_reference';
    case Station = 'station';
    case None = 'none';
}
