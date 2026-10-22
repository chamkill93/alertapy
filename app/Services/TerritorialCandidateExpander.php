<?php

namespace App\Services;

use App\DTOs\CandidateDTO;
use Illuminate\Support\Collection;

class TerritorialCandidateExpander
{
    public function __construct(private readonly ParaguayLocationExtractor $locations) {}

    /**
     * @param  Collection<int, CandidateDTO>  $candidates
     * @return Collection<int, CandidateDTO>
     */
    public function expand(Collection $candidates): Collection
    {
        return $candidates->flatMap(function (CandidateDTO $candidate): array {
            $evidence = (string) ($candidate->metadata['location_evidence'] ?? $candidate->title);
            $locations = $this->locations->extractAll($evidence);

            if (count($locations) <= 1) {
                return [$candidate];
            }

            return array_map(function (array $location) use ($candidate): CandidateDTO {
                if (($candidate->metadata['force_multi_location_department'] ?? false) && $location['department'] !== null) {
                    $location = [
                        'text' => $location['department'],
                        'query' => 'Departamento de '.$location['department'],
                        'city' => null,
                        'department' => $location['department'],
                        'accuracy' => 'department',
                    ];
                }

                $suffix = hash('sha256', $location['query']);

                return new CandidateDTO(
                    sourceSlug: $candidate->sourceSlug,
                    sourceType: $candidate->sourceType,
                    externalIdentifier: ($candidate->externalIdentifier ?? hash('sha256', $candidate->originalUrl)).'-'.$suffix,
                    title: $candidate->title,
                    originalUrl: $candidate->originalUrl,
                    content: $candidate->content." Ámbito territorial: {$location['text']}.",
                    publishedAt: $candidate->publishedAt,
                    detectedLocation: $location['query'],
                    metadata: [...$candidate->metadata, ...[
                        'location_text' => $location['text'],
                        'city' => $location['city'],
                        'department' => $location['department'],
                        'location_accuracy' => $location['accuracy'],
                        'multi_territory' => true,
                    ]],
                );
            }, $locations);
        })->values();
    }
}
