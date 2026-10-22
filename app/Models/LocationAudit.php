<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LocationAudit extends Model
{
    protected $fillable = [
        'incident_id', 'old_latitude', 'old_longitude', 'new_latitude', 'new_longitude',
        'old_method', 'new_method', 'reason', 'source', 'metadata_json', 'audited_at',
    ];

    protected function casts(): array
    {
        return [
            'old_latitude' => 'float', 'old_longitude' => 'float',
            'new_latitude' => 'float', 'new_longitude' => 'float',
            'metadata_json' => 'array', 'audited_at' => 'datetime',
        ];
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }
}
