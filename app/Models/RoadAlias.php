<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoadAlias extends Model
{
    protected $fillable = ['road_id', 'alias', 'normalized_alias', 'source'];
    public function road(): BelongsTo { return $this->belongsTo(Road::class); }
}
