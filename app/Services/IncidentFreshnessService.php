<?php

namespace App\Services;

use App\Enums\FreshnessStatus;
use App\Enums\LifecycleStatus;
use App\Models\Incident;

class IncidentFreshnessService
{
    public function forIncident(Incident $incident): FreshnessStatus
    {
        $lifecycle = app(IncidentLifecycleService::class)->derive($incident);
        if (in_array($lifecycle, [LifecycleStatus::Resolved, LifecycleStatus::Expired, LifecycleStatus::Discarded], true)) {
            return FreshnessStatus::Archived;
        }

        $reference = $incident->last_seen_at ?? $incident->updated_at ?? $incident->published_at;
        if ($reference === null) return FreshnessStatus::Archived;
        $policy = config('alertapy_freshness.'.($incident->category?->slug ?? 'default'), config('alertapy_freshness.default'));
        $hours = $reference->diffInMinutes(now(), false) / 60;

        return match (true) {
            $hours <= (float) $policy['live'] => FreshnessStatus::Live,
            $hours <= (float) $policy['recent'] => FreshnessStatus::Recent,
            $hours <= (float) $policy['aging'] => FreshnessStatus::Aging,
            $hours <= (float) $policy['stale'] => FreshnessStatus::Stale,
            default => FreshnessStatus::Archived,
        };
    }
}
