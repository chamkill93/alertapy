<?php

namespace App\Services;

use App\Enums\FreshnessStatus;
use App\Enums\LocationQuality;
use App\Enums\SourceType;
use App\Enums\TemporalAccuracy;
use App\Models\Incident;

class IncidentDataQualityService
{
    public function __construct(private readonly IncidentFreshnessService $freshness) {}

    /** @return array<string, int|string> */
    public function forIncident(Incident $incident): array
    {
        $source = match ($incident->source_type) { SourceType::Official, SourceType::Sensor => 100, SourceType::Press => 85, SourceType::Community => 55, default => 35 };
        $location = match ($incident->location_quality) { LocationQuality::VeryHigh => 100, LocationQuality::High => 85, LocationQuality::Medium => 70, LocationQuality::Low => 45, LocationQuality::VeryLow => 25, default => 10 };
        $temporal = match ($incident->occurred_at_accuracy) { TemporalAccuracy::Exact => 100, TemporalAccuracy::Approximate, TemporalAccuracy::RelativeParsed => 75, TemporalAccuracy::DateOnly => 55, default => 30 };
        $sourceCount = $incident->relationLoaded('sources') ? $incident->sources->unique('source_id')->count() : $incident->sources()->distinct('source_id')->count('source_id');
        $evidence = min(100, 55 + ($sourceCount * 20));
        $freshness = $this->freshness->forIncident($incident);
        $freshnessScore = match ($freshness) { FreshnessStatus::Live => 100, FreshnessStatus::Recent => 85, FreshnessStatus::Aging => 60, FreshnessStatus::Stale => 35, FreshnessStatus::Archived => 20 };
        return ['source' => $source, 'location' => $location, 'temporal' => $temporal, 'evidence' => $evidence, 'freshness' => $freshnessScore, 'freshness_status' => $freshness->value];
    }
}
