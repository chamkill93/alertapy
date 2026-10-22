<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoadSegment extends Model
{
    protected $fillable = ['road_id', 'sequence', 'start_lat', 'start_lng', 'end_lat', 'end_lng', 'segment_length_meters', 'cumulative_start_meters', 'cumulative_end_meters', 'bbox_north', 'bbox_south', 'bbox_east', 'bbox_west', 'department', 'district', 'city'];
    protected function casts(): array { return ['sequence' => 'integer', 'start_lat' => 'float', 'start_lng' => 'float', 'end_lat' => 'float', 'end_lng' => 'float', 'segment_length_meters' => 'float', 'cumulative_start_meters' => 'float', 'cumulative_end_meters' => 'float']; }
    public function road(): BelongsTo { return $this->belongsTo(Road::class); }
}
