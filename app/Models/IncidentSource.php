<?php

namespace App\Models;

use App\Enums\SourceRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IncidentSource extends Model
{
    protected $fillable = [
        'incident_id',
        'source_id',
        'raw_item_id',
        'original_title',
        'original_url',
        'published_at',
        'detected_at',
        'source_role',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'detected_at' => 'datetime',
            'source_role' => SourceRole::class,
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
