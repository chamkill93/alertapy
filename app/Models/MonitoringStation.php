<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MonitoringStation extends Model
{
    protected $fillable = ['source_id', 'external_id', 'name', 'kind', 'city', 'department', 'latitude', 'longitude', 'is_active', 'is_collecting_data', 'is_under_maintenance', 'last_observed_at', 'metadata'];

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'is_active' => 'boolean',
            'is_collecting_data' => 'boolean',
            'is_under_maintenance' => 'boolean',
            'last_observed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    public function measurements(): HasMany
    {
        return $this->hasMany(StationMeasurement::class);
    }
}
