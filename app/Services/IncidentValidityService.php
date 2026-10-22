<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class IncidentValidityService
{
    public const CATEGORY_HOURS = ['roads' => 8, 'weather_flood' => 12, 'traffic_accident' => 18, 'fire' => 18, 'emergency' => 18];

    public static function hoursForCategory(string $category): int
    {
        return self::CATEGORY_HOURS[$category] ?? 24;
    }

    public static function expiresAt(CarbonInterface $reference, string $category): CarbonImmutable
    {
        return CarbonImmutable::instance($reference)->addHours(self::hoursForCategory($category));
    }
}
