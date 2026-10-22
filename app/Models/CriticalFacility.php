<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CriticalFacility extends Model
{
    protected $fillable = ['external_id', 'kind', 'name', 'category', 'department', 'district', 'neighborhood', 'latitude', 'longitude', 'source_name', 'source_url', 'synced_at'];

    protected function casts(): array
    {
        return ['latitude' => 'float', 'longitude' => 'float', 'synced_at' => 'datetime'];
    }
}
