<?php

namespace App\Http\Resources;

use App\Services\IncidentFreshnessService;
use App\Services\IncidentLifecycleService;
use App\Services\IncidentVerificationService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class IncidentSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $lifecycle = app(IncidentLifecycleService::class)->derive($this->resource);
        $freshness = app(IncidentFreshnessService::class)->forIncident($this->resource);
        $verification = app(IncidentVerificationService::class)->forIncident($this->resource);
        return [
            'id' => $this->public_id,
            'title' => $this->title,
            'summary' => $this->summary,
            'subcategory' => $this->subcategory,
            'category' => [
                'slug' => $this->category->slug,
                'name' => $this->category->name,
                'icon' => $this->category->icon,
                'color' => $this->category->color,
            ],
            'source' => [
                'type' => $this->source_type->value,
                'label' => $this->source_type->label(),
                'icon' => $this->source_type->icon(),
                'count' => $this->relationLoaded('sources')
                    ? $this->sources->unique('original_url')->count()
                    : (int) ($this->sources_count ?? 0),
                'verification' => $this->verification_level,
            ],
            'verification_level' => ['value'=>$verification->value, 'label'=>$verification->label()],
            'status' => [
                'value' => $this->status->value,
                'label' => $this->status->label(),
                'lifecycle' => $lifecycle->value,
                'lifecycle_label' => $lifecycle->label(),
            ],
            'freshness' => ['value'=>$freshness->value, 'label'=>$freshness->label()],
            'border_status' => $this->border_status,
            'representation' => [
                'type' => $this->representation_type,
                'geometry_type' => $this->geometry_type?->value,
                'geometry' => $this->geometry_geojson,
            ],
            'severity' => [
                'value' => $this->severity->value,
                'label' => $this->severity->label(),
            ],
            'location' => [
                'latitude' => $this->latitude,
                'longitude' => $this->longitude,
                'accuracy' => $this->location_accuracy->value,
                'accuracy_label' => $this->location_accuracy->label(),
                'is_approximate' => $this->location_accuracy->isApproximate(),
                'method' => $this->location_method?->value,
                'quality' => $this->location_quality?->value,
                'quality_label' => $this->location_quality?->label(),
                'quality_flags' => $this->location_quality_flags ?? [],
                'department' => $this->department,
                'district' => $this->district,
                'city' => $this->city,
                'neighborhood' => $this->neighborhood,
                'text' => $this->location_text,
                'road' => $this->road_code ? [
                    'code' => $this->road_code,
                    'kilometer' => $this->road_kilometer,
                    'method' => $this->road_location_method,
                    'estimated_error_meters' => $this->location_error_meters,
                ] : null,
            ],
            'scope' => $this->incident_scope?->value,
            'occurred_at' => $this->occurred_at?->toIso8601String(),
            'occurred_at_accuracy' => $this->occurred_at_accuracy?->value,
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'published_at' => $this->published_at?->toIso8601String(),
            'detected_at' => $this->detected_at?->toIso8601String(),
            'first_seen_at' => $this->first_seen_at?->toIso8601String(),
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'expired_at' => $this->expired_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
