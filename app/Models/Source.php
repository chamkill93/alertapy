<?php

namespace App\Models;

use App\Enums\GeorefMode;
use App\Enums\IngestionType;
use App\Enums\SourceType;
use App\Enums\TrustLevel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Source extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'type',
        'ingestion_type',
        'georef_mode',
        'base_url',
        'domain',
        'enabled',
        'polling_minutes',
        'trust_level',
        'requires_ai_decision',
        'canonical_url_required',
        'supports_etag',
        'supports_last_modified',
        'last_run_at',
        'last_success_at',
        'last_change_at',
        'last_http_status',
        'last_error',
        'last_error_type',
        'last_duration_ms',
        'items_seen',
        'items_new',
        'last_new_item_at',
        'consecutive_errors',
        'etag',
        'last_modified',
        'content_hash',
        'configuration_json',
    ];

    protected function casts(): array
    {
        return [
            'type' => SourceType::class,
            'ingestion_type' => IngestionType::class,
            'georef_mode' => GeorefMode::class,
            'trust_level' => TrustLevel::class,
            'enabled' => 'boolean',
            'polling_minutes' => 'integer',
            'requires_ai_decision' => 'boolean',
            'canonical_url_required' => 'boolean',
            'supports_etag' => 'boolean',
            'supports_last_modified' => 'boolean',
            'last_run_at' => 'datetime',
            'last_success_at' => 'datetime',
            'last_change_at' => 'datetime',
            'last_new_item_at' => 'datetime',
            'last_http_status' => 'integer',
            'last_duration_ms' => 'integer',
            'items_seen' => 'integer',
            'items_new' => 'integer',
            'consecutive_errors' => 'integer',
            'configuration_json' => 'array',
        ];
    }

    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('enabled', true);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(SourceRun::class);
    }

    public function rawItems(): HasMany
    {
        return $this->hasMany(RawItem::class);
    }

    public function candidates(): HasMany
    {
        return $this->hasMany(ContentCandidate::class);
    }

    public function latestRun(): HasOne
    {
        return $this->hasOne(SourceRun::class)->latestOfMany('started_at');
    }

    public function parserSnapshots(): HasMany
    {
        return $this->hasMany(SourceParserSnapshot::class);
    }
}
