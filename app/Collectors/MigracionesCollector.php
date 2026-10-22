<?php

namespace App\Collectors;

use App\Contracts\Collectors\CollectorInterface;
use App\DTOs\CandidateDTO;
use App\Models\Source;
use App\Services\TerritorialCandidateExpander;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class MigracionesCollector implements CollectorInterface
{
    public function __construct(
        private readonly PressHomepageCollector $collector,
        private readonly TerritorialCandidateExpander $expander,
    ) {}

    /** @return Collection<int, CandidateDTO> */
    public function collect(Source $source): Collection
    {
        return $this->expander->expand($this->collector->collect($source))
            ->map(function (CandidateDTO $candidate): CandidateDTO {
                $evidence = Str::lower(Str::ascii($candidate->title.' '.$candidate->content));
                $status = match (true) {
                    preg_match('/\b(cierre|cerrad|suspend)\w*/u', $evidence) === 1 => 'CLOSED',
                    preg_match('/\b(restric|limitad)\w*/u', $evidence) === 1 => 'RESTRICTED',
                    default => 'MODIFIED_OPERATION',
                };

                return new CandidateDTO(
                    sourceSlug: $candidate->sourceSlug,
                    sourceType: $candidate->sourceType,
                    externalIdentifier: $candidate->externalIdentifier,
                    title: $candidate->title,
                    originalUrl: $candidate->originalUrl,
                    content: $candidate->content,
                    publishedAt: $candidate->publishedAt,
                    detectedLocation: $candidate->detectedLocation,
                    latitude: $candidate->latitude,
                    longitude: $candidate->longitude,
                    metadata: [...$candidate->metadata, 'border_status' => $status],
                );
            });
    }
}
