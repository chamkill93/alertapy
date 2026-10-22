<?php

namespace App\Enums;

enum IncidentScope: string
{
    case Local = 'local';
    case Neighborhood = 'neighborhood';
    case City = 'city';
    case District = 'district';
    case Department = 'department';
    case MultiDepartment = 'multi_department';
    case National = 'national';
    case RouteSegment = 'route_segment';
    case Unknown = 'unknown';
}
