<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TerritorialGeometry extends Model
{
    protected $fillable = ['location_id', 'geometry_type', 'geometry_json', 'bbox_north', 'bbox_south', 'bbox_east', 'bbox_west', 'source', 'source_date'];
    protected function casts(): array { return ['geometry_json' => 'array', 'source_date' => 'date', 'bbox_north' => 'float', 'bbox_south' => 'float', 'bbox_east' => 'float', 'bbox_west' => 'float']; }
    public function location(): BelongsTo { return $this->belongsTo(Location::class); }
}
