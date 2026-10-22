<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RawItem extends Model
{
    protected $fillable = [
        'source_id',
        'external_identifier',
        'original_url',
        'title',
        'raw_content',
        'content_hash',
        'published_at',
        'detected_at',
        'processed_at',
    ];

    protected $hidden = ['raw_content'];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'detected_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    public function candidates(): HasMany
    {
        return $this->hasMany(ContentCandidate::class);
    }
}
