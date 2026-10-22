<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoadReferencePoint extends Model
{
    protected $fillable = ['road_id', 'source_dataset_id', 'reference_type', 'kilometer', 'latitude', 'longitude', 'name', 'source_url', 'accuracy'];
    protected function casts(): array { return ['kilometer' => 'float', 'latitude' => 'float', 'longitude' => 'float']; }
    public function road(): BelongsTo { return $this->belongsTo(Road::class); }
    public function dataset(): BelongsTo { return $this->belongsTo(RoadDataset::class, 'source_dataset_id'); }
}
