<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RoadDataset extends Model
{
    protected $fillable = ['name', 'source_name', 'source_url', 'source_date', 'file_name', 'file_hash', 'format', 'imported_at', 'feature_count', 'status', 'metadata_json'];

    protected function casts(): array
    {
        return ['source_date' => 'date', 'imported_at' => 'datetime', 'feature_count' => 'integer', 'metadata_json' => 'array'];
    }

    public function roads(): HasMany { return $this->hasMany(Road::class, 'source_dataset_id'); }
    public function referencePoints(): HasMany { return $this->hasMany(RoadReferencePoint::class, 'source_dataset_id'); }
}
