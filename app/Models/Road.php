<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Road extends Model
{
    protected $fillable = ['source_dataset_id', 'code', 'normalized_code', 'name', 'official_name', 'classification', 'department', 'district', 'geometry_type', 'geometry_json', 'bbox_north', 'bbox_south', 'bbox_east', 'bbox_west', 'length_meters', 'direction_start_label', 'direction_end_label', 'active'];
    protected function casts(): array { return ['geometry_json' => 'array', 'length_meters' => 'float', 'active' => 'boolean', 'bbox_north' => 'float', 'bbox_south' => 'float', 'bbox_east' => 'float', 'bbox_west' => 'float']; }
    public function scopeActive(Builder $query): Builder { return $query->where('active', true); }
    public function dataset(): BelongsTo { return $this->belongsTo(RoadDataset::class, 'source_dataset_id'); }
    public function aliases(): HasMany { return $this->hasMany(RoadAlias::class); }
    public function segments(): HasMany { return $this->hasMany(RoadSegment::class)->orderBy('sequence'); }
    public function referencePoints(): HasMany { return $this->hasMany(RoadReferencePoint::class)->orderBy('kilometer'); }
    public function incidents(): HasMany { return $this->hasMany(Incident::class); }
}
