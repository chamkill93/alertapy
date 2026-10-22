<?php

namespace App\Models;

use App\Enums\LocationAccuracy;
use Illuminate\Database\Eloquent\Model;

class GeocodingCache extends Model
{
    protected $table = 'geocoding_cache';

    protected $fillable = [
        'query',
        'normalized_query',
        'latitude',
        'longitude',
        'precision',
        'provider',
        'provider_reference',
        'hit_count',
        'last_used_at',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'precision' => LocationAccuracy::class,
            'hit_count' => 'integer',
            'last_used_at' => 'datetime',
        ];
    }
}
