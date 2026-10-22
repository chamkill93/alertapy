<?php

namespace App\DTOs;

use App\Enums\SourceType;
use Carbon\CarbonImmutable;

final readonly class DiscoveryItemDTO
{
    public function __construct(
        public string $externalIdentifier,
        public string $title,
        public string $originalUrl,
        public string $content,
        public ?CarbonImmutable $publishedAt,
        public SourceType $sourceType,
        public ?string $author = null,
        public ?string $originalDomain = null,
    ) {}
}
