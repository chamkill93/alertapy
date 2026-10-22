<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TerritorialProfile extends Model
{
    protected $fillable = [
        'level',
        'ine_department_code',
        'ine_district_code',
        'department',
        'district',
        'normalized_department',
        'normalized_district',
        'population',
        'census_year',
        'source_name',
        'source_url',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'population' => 'integer',
            'census_year' => 'integer',
            'synced_at' => 'datetime',
        ];
    }
}
