<?php

namespace App\Models;

use App\Enums\SourceType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentCandidate extends Model
{
    protected $fillable = [
        'source_id',
        'raw_item_id',
        'source_type',
        'status',
        'normalized_content',
        'detected_location',
        'road_code',
        'road_kilometer',
        'location_evidence_json',
        'evidence_score',
        'evidence_components_json',
        'latitude',
        'longitude',
        'ai_status',
        'ai_decision',
        'ai_confidence',
        'ai_reason',
        'ai_model',
        'ai_raw_response',
        'attempts',
        'next_retry_at',
        'processed_at',
    ];

    protected $hidden = ['ai_raw_response'];

    protected function casts(): array
    {
        return [
            'source_type' => SourceType::class,
            'latitude' => 'float',
            'longitude' => 'float',
            'road_kilometer' => 'float',
            'location_evidence_json' => 'array',
            'evidence_score' => 'float',
            'evidence_components_json' => 'array',
            'ai_confidence' => 'float',
            'attempts' => 'integer',
            'next_retry_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    public function rawItem(): BelongsTo
    {
        return $this->belongsTo(RawItem::class);
    }
}
