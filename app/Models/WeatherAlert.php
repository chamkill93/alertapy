<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WeatherAlert extends Model
{
    protected $fillable = ['external_id', 'event', 'headline', 'description', 'severity', 'urgency', 'certainty', 'area_description', 'geometry_geojson', 'sent_at', 'starts_at', 'expires_at', 'source_url', 'synced_at'];

    protected function casts(): array
    {
        return ['geometry_geojson' => 'array', 'sent_at' => 'datetime', 'starts_at' => 'datetime', 'expires_at' => 'datetime', 'synced_at' => 'datetime'];
    }
}
