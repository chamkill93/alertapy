<?php

namespace App\DTOs;

final readonly class RoadLocationDTO
{
    /** @param array<string, mixed> $evidence */
    public function __construct(
        public string $originalText,
        public ?int $roadId = null,
        public ?string $roadCode = null,
        public ?float $kilometer = null,
        public ?int $relativeOffsetM = null,
        public ?string $department = null,
        public ?string $district = null,
        public ?string $city = null,
        public ?string $neighborhood = null,
        public ?string $intersectionText = null,
        public ?float $latitude = null,
        public ?float $longitude = null,
        public string $resolutionMethod = 'ROUTE_ONLY',
        public string $accuracy = 'route_only',
        public ?int $estimatedErrorMeters = null,
        public bool $directionUnknown = false,
        public array $evidence = [],
    ) {}

    public function hasCoordinates(): bool { return $this->latitude !== null && $this->longitude !== null; }
}
