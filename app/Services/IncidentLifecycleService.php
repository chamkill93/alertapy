<?php

namespace App\Services;

use App\Enums\IncidentStatus;
use App\Enums\LifecycleStatus;
use App\Models\Incident;

class IncidentLifecycleService
{
    public function derive(Incident $incident): LifecycleStatus
    {
        if ($incident->status === IncidentStatus::Cancelled) {
            return LifecycleStatus::Discarded;
        }
        if ($incident->resolved_at !== null) {
            return LifecycleStatus::Resolved;
        }
        if ($incident->expired_at !== null) {
            return LifecycleStatus::Expired;
        }
        if ($incident->finalized_at !== null || $incident->status === IncidentStatus::Finalized) {
            return LifecycleStatus::Resolved;
        }
        if ($incident->expires_at?->lte(now())) {
            return LifecycleStatus::Expired;
        }
        if (in_array($incident->status, [IncidentStatus::UnderVerification, IncidentStatus::Reported], true)) {
            return LifecycleStatus::Detected;
        }
        if ($incident->status === IncidentStatus::Confirmed) {
            return LifecycleStatus::Monitoring;
        }

        return LifecycleStatus::Active;
    }
}
