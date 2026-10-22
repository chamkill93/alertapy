<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LocationAlias extends Model
{
    protected $fillable = [
        'location_id',
        'alias',
        'normalized_alias',
        'priority',
    ];

    protected function casts(): array
    {
        return ['priority' => 'integer'];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
