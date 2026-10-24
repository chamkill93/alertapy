<?php

namespace App\Services;

use App\DTOs\CandidateDTO;
use App\DTOs\GeocodingResult;
use App\Enums\SourceType;
use App\Models\Source;
use Illuminate\Support\Str;

class EvidenceScorer
{
    public function score(Source $source, CandidateDTO $candidate, ?GeocodingResult $location): array
    {
        $components = [
            'official_source' => $source->type === SourceType::Official ? .3 : 0,
            'press_allowlist' => $source->type === SourceType::Press ? .18 : 0,
            'source_url' => filter_var($candidate->originalUrl, FILTER_VALIDATE_URL) ? .12 : -.35,
            'valid_date' => ($candidate->publishedAt !== null || ! empty($candidate->metadata['starts_at'])) ? .12 : -.2,
            'precise_location' => ($candidate->metadata['location_is_fallback'] ?? false)
                ? -.35
                : ($location !== null ? ($location->precision->isApproximate() ? .1 : .18) : -.35),
            'recent_timestamp' => $candidate->publishedAt?->greaterThan(now()->subDays(7)) || ! empty($candidate->metadata['starts_at']) ? .08 : 0,
            'personal_accusation' => preg_match('/\b(?:acus[oa]|culpable|delincuente)\s+[A-ZÁÉÍÓÚÑ][\pL]+\s+[A-ZÁÉÍÓÚÑ][\pL]+/u', $candidate->title.' '.$candidate->content) === 1 ? -.8 : 0,
            'outside_paraguay' => $location !== null && ($location->latitude < -27.8 || $location->latitude > -19 || $location->longitude < -63 || $location->longitude > -54) ? -1 : 0,
        ];
        return ['score' => max(0, min(1, array_sum($components) + .2)), 'components' => $components];
    }
}
