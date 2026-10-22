<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FavoriteZone extends Model
{
    protected $fillable = ['user_id', 'anonymous_token', 'name', 'icon', 'latitude', 'longitude', 'radius_km', 'electricity_enabled', 'water_enabled', 'security_enabled', 'traffic_enabled', 'fire_enabled', 'weather_enabled', 'flood_enabled', 'health_enabled', 'enabled'];
    protected $hidden = ['anonymous_token'];
    protected function casts(): array { return ['latitude' => 'float', 'longitude' => 'float', 'radius_km' => 'float', 'electricity_enabled' => 'boolean', 'water_enabled' => 'boolean', 'security_enabled' => 'boolean', 'traffic_enabled' => 'boolean', 'fire_enabled' => 'boolean', 'weather_enabled' => 'boolean', 'flood_enabled' => 'boolean', 'health_enabled' => 'boolean', 'enabled' => 'boolean']; }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
