<?php

namespace App\DTOs;

use App\Enums\LocationAccuracy;

final readonly class GeocodingResult
{
    public function __construct(
        public float $latitude,
        public float $longitude,
        public LocationAccuracy $precision,
        public string $provider,
        public string $query,
    ) {}
}
