<?php

namespace App\Models;

use App\Enums\IncidentStatus;
use App\Enums\IncidentGeometryType;
use App\Enums\IncidentScope;
use App\Enums\LifecycleStatus;
use App\Enums\LocationAccuracy;
use App\Enums\LocationMethod;
use App\Enums\LocationQuality;
use App\Enums\Severity;
use App\Enums\SourceType;
use App\Enums\TemporalAccuracy;
use App\Services\DataRetentionService;
use App\Services\IncidentValidityService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Incident extends Model
{
    use HasFactory;

    protected $fillable = [
        'public_id',
        'category_id',
        'title',
        'summary',
        'subcategory',
        'source_type',
        'verification_level',
        'status',
        'border_status',
        'representation_type',
        'geometry_type',
        'geometry_geojson',
        'road_id',
        'road_code',
        'road_kilometer',
        'road_location_method',
        'location_error_meters',
        'location_method',
        'location_quality',
        'location_quality_flags',
        'location_evidence_json',
        'impact_method',
        'impact_evidence_json',
        'severity',
        'latitude',
        'longitude',
        'location_accuracy',
        'department',
        'district',
        'city',
        'neighborhood',
        'location_text',
        'community_link',
        'community_photo_path',
        'starts_at',
        'ends_at',
        'expires_at',
        'occurred_at',
        'occurred_at_accuracy',
        'temporal_evidence_json',
        'detected_at',
        'first_seen_at',
        'last_seen_at',
        'resolved_at',
        'expired_at',
        'lifecycle_status',
        'incident_scope',
        'publication_explanation_json',
        'data_quality_json',
        'published_at',
        'finalized_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (Incident $incident): void {
            $incident->public_id ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return [
            'source_type' => SourceType::class,
            'geometry_type' => IncidentGeometryType::class,
            'geometry_geojson' => 'array',
            'road_kilometer' => 'float',
            'location_error_meters' => 'integer',
            'location_evidence_json' => 'array',
            'location_method' => LocationMethod::class,
            'location_quality' => LocationQuality::class,
            'location_quality_flags' => 'array',
            'impact_evidence_json' => 'array',
            'status' => IncidentStatus::class,
            'severity' => Severity::class,
            'location_accuracy' => LocationAccuracy::class,
            'latitude' => 'float',
            'longitude' => 'float',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'expires_at' => 'datetime',
            'occurred_at' => 'datetime',
            'occurred_at_accuracy' => TemporalAccuracy::class,
            'temporal_evidence_json' => 'array',
            'detected_at' => 'datetime',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'resolved_at' => 'datetime',
            'expired_at' => 'datetime',
            'lifecycle_status' => LifecycleStatus::class,
            'incident_scope' => IncidentScope::class,
            'publication_explanation_json' => 'array',
            'data_quality_json' => 'array',
            'published_at' => 'datetime',
            'finalized_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }

    public function scopeCurrent(Builder $query): Builder
    {
        $currentTime = now();

        return $query
            ->whereIn('status', array_map(
                static fn (IncidentStatus $status): string => $status->value,
                array_filter(IncidentStatus::cases(), static fn (IncidentStatus $status): bool => $status->isCurrent()),
            ))
            ->whereNull('resolved_at')
            ->whereNull('expired_at')
            ->whereNull('finalized_at')
            ->where(function (Builder $query): void {
                $query->whereNull('lifecycle_status')->orWhereNotIn('lifecycle_status', [
                    LifecycleStatus::Resolved->value,
                    LifecycleStatus::Expired->value,
                    LifecycleStatus::Discarded->value,
                ]);
            })
            ->where(function (Builder $query) use ($currentTime): void {
                $query->whereNull('starts_at')->orWhere('starts_at', '<=', $currentTime);
            })
            ->where(function (Builder $query) use ($currentTime): void {
                $query->whereNull('occurred_at')->orWhere('occurred_at', '<=', $currentTime);
            })
            ->where(function (Builder $query) use ($currentTime): void {
                $query->whereNull('ends_at')->orWhere('ends_at', '>', $currentTime);
            })
            ->where(function (Builder $query) use ($currentTime): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', $currentTime);
            })
            ->where(function (Builder $query) use ($currentTime): void {
                // A known validity interval is authoritative. Legacy items without
                // an end must still age out; a scrape or technical edit is not new evidence.
                $query->whereNotNull('expires_at')->orWhereNotNull('ends_at')
                    ->orWhere(function (Builder $query) use ($currentTime): void {
                        $windows = [];
                        foreach (IncidentValidityService::CATEGORY_HOURS as $category => $hours) {
                            $windows[$hours][] = $category;
                        }
                        foreach ($windows as $hours => $categories) {
                            $query->orWhere(function (Builder $query) use ($currentTime, $hours, $categories): void {
                                $query->whereHas('category', fn (Builder $category) => $category->whereIn('slug', $categories))
                                    ->whereRaw('COALESCE(occurred_at, starts_at, published_at, detected_at, created_at) > ?', [$currentTime->copy()->subHours($hours)]);
                            });
                        }
                        $query->orWhere(function (Builder $query) use ($currentTime, $windows): void {
                            $query->whereDoesntHave('category', fn (Builder $category) => $category->whereIn('slug', array_merge(...array_values($windows))))
                                ->whereRaw('COALESCE(occurred_at, starts_at, published_at, detected_at, created_at) > ?', [$currentTime->copy()->subHours(IncidentValidityService::hoursForCategory('default'))]);
                        });
                    });
            });
    }

    public function scopeWithinRetention(Builder $query): Builder
    {
        $cutoff = app(DataRetentionService::class)->cutoff();

        return $query->whereRaw('COALESCE(occurred_at, starts_at, published_at, detected_at, created_at) >= ?', [$cutoff])
            ->whereRaw('COALESCE(published_at, detected_at, created_at) >= ?', [$cutoff]);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function sources(): HasMany
    {
        return $this->hasMany(IncidentSource::class);
    }

    public function updates(): HasMany
    {
        return $this->hasMany(IncidentUpdate::class)->orderBy('occurred_at');
    }

    public function communityVotes(): HasMany
    {
        return $this->hasMany(CommunityIncidentVote::class);
    }

    public function locationAudits(): HasMany
    {
        return $this->hasMany(LocationAudit::class);
    }

    public function road(): BelongsTo
    {
        return $this->belongsTo(Road::class);
    }

    public function usesReferenceLocation(): bool
    {
        return data_get($this->location_evidence_json, 'method') === \App\Services\MapLocationFallback::METHOD;
    }
}
