<?php

namespace App\Http\Resources;

use App\Enums\IncidentStatus;
use App\Enums\SourceType;
use App\Services\IncidentImpactService;
use App\Services\NearbyInfrastructureService;
use App\Services\ClimateContextService;
use App\Services\IncidentDataQualityService;
use App\Services\PublicationExplanationService;
use Illuminate\Http\Request;

class IncidentDetailResource extends IncidentSummaryResource
{
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'territorial_impact' => app(IncidentImpactService::class)->forIncident($this->resource),
            'nearby_infrastructure' => $this->latitude !== null && $this->longitude !== null
                ? app(NearbyInfrastructureService::class)->summary((float) $this->latitude, (float) $this->longitude, 2)
                : null,
            'climate_context' => app(ClimateContextService::class)->forIncident($this->resource),
            'publication_explanation' => $this->publication_explanation_json
                ?? app(PublicationExplanationService::class)->forIncident($this->resource),
            'data_quality' => $this->data_quality_json
                ?? app(IncidentDataQualityService::class)->forIncident($this->resource),
            'sources' => $this->sources->unique('original_url')->map(fn ($incidentSource): array => [
                'name' => $incidentSource->source->name,
                'type' => $incidentSource->source->type->value,
                'original_title' => $incidentSource->original_title,
                'original_url' => $incidentSource->original_url,
                'published_at' => $incidentSource->published_at?->toIso8601String(),
                'detected_at' => $incidentSource->detected_at->toIso8601String(),
                'role' => $incidentSource->source_role?->value,
            ])->values(),
            'updates' => $this->updates->unique(fn ($update): string => implode('|', [
                $update->raw_item_id,
                $update->summary,
                $update->occurred_at?->toIso8601String(),
            ]))->map(fn ($update): array => [
                'title' => $update->title,
                'summary' => $update->summary,
                'status' => $update->status?->value,
                'event_type' => $update->event_type?->value,
                'metadata' => $update->metadata_json,
                'source_name' => $update->source?->name,
                'occurred_at' => $update->occurred_at->toIso8601String(),
            ])->values(),
            'community_report' => $this->source_type === SourceType::Community ? [
                'link' => $this->community_link,
                'photo_url' => $this->community_photo_path !== null
                    ? route('api.incidents.photo', ['publicId' => $this->public_id], false)
                    : null,
            ] : null,
            'community_validation' => $this->source_type === SourceType::Community ? [
                'positive' => (int) ($this->community_positive_votes_count ?? 0),
                'negative' => (int) ($this->community_negative_votes_count ?? 0),
                'negative_threshold' => 5,
                'accepting_votes' => $this->status !== IncidentStatus::Cancelled,
            ] : null,
        ];
    }
}
