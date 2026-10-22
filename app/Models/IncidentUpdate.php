<?php

namespace App\Models;

use App\Enums\IncidentEventType;
use App\Enums\IncidentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IncidentUpdate extends Model
{
    protected $fillable = [
        'incident_id',
        'source_id',
        'raw_item_id',
        'title',
        'summary',
        'status',
        'occurred_at',
        'event_type',
        'metadata_json',
    ];

    protected function casts(): array
    {
        return [
            'status' => IncidentStatus::class,
            'occurred_at' => 'datetime',
            'event_type' => IncidentEventType::class,
            'metadata_json' => 'array',
        ];
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
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
