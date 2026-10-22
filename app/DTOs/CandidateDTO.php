<?php

namespace App\DTOs;

use App\Enums\SourceType;
use Carbon\CarbonImmutable;

final readonly class CandidateDTO
{
    /** @param array<string, scalar|null> $metadata */
    public function __construct(
        public string $sourceSlug,
        public SourceType $sourceType,
        public ?string $externalIdentifier,
        public string $title,
        public string $originalUrl,
        public string $content,
        public ?CarbonImmutable $publishedAt,
        public ?string $detectedLocation = null,
        public ?float $latitude = null,
        public ?float $longitude = null,
        public array $metadata = [],
    ) {}
}
