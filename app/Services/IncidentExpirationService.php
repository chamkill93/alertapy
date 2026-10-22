<?php

namespace App\Services;

use App\Enums\IncidentEventType;
use App\Enums\IncidentStatus;
use App\Enums\LifecycleStatus;
use App\Models\Incident;
use App\Models\IncidentUpdate;

class IncidentExpirationService
{
    /** @return array{checked: int, expired: int} */
    public function expire(bool $dryRun = false, ?int $limit = null): array
    {
        // current() excludes expired timestamps; select current statuses instead.
        $currentStatuses = array_map(
            static fn (IncidentStatus $status): string => $status->value,
            array_filter(IncidentStatus::cases(), static fn (IncidentStatus $status): bool => $status->isCurrent()),
        );
        $query = Incident::query()->whereIn('status', $currentStatuses)
            ->whereNotNull('expires_at')->where('expires_at', '<=', now())->orderBy('id');
        if ($limit !== null) {
            $query->limit(max(1, $limit));
        }
        $incidents = $query->get();
        $expired = 0;
        foreach ($incidents as $incident) {
            $expired++;
            if ($dryRun) {
                continue;
            }
            $incident->forceFill(['lifecycle_status' => LifecycleStatus::Expired, 'expired_at' => $incident->expires_at, 'status' => IncidentStatus::Finalized])->save();
            IncidentUpdate::query()->create(['incident_id' => $incident->id, 'title' => 'Vigencia finalizada', 'summary' => 'La incidencia venció según la política temporal de su categoría.', 'status' => IncidentStatus::Finalized, 'event_type' => IncidentEventType::Expired, 'occurred_at' => $incident->expires_at, 'metadata_json' => ['method' => 'CATEGORY_EXPIRATION_POLICY']]);
        }

        return ['checked' => $incidents->count(), 'expired' => $expired];
    }
}
