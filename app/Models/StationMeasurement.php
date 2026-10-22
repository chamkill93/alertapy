<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StationMeasurement extends Model
{
    protected $fillable = ['monitoring_station_id', 'parameter', 'value', 'unit', 'measured_at', 'metadata'];

    protected function casts(): array
    {
        return ['value' => 'float', 'measured_at' => 'datetime', 'metadata' => 'array'];
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(MonitoringStation::class, 'monitoring_station_id');
    }
}
