<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SourceRun extends Model
{
    protected $fillable = [
        'source_id',
        'started_at',
        'finished_at',
        'status',
        'http_status',
        'discovered',
        'created_candidates',
        'duration_ms',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'http_status' => 'integer',
            'discovered' => 'integer',
            'created_candidates' => 'integer',
            'duration_ms' => 'integer',
        ];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }
}
