<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SourceParserSnapshot extends Model
{
    protected $fillable = ['source_id', 'element_count', 'content_length', 'structure_signature', 'page_hash', 'parser_version', 'structure_changed', 'observed_at'];
    protected function casts(): array { return ['element_count' => 'integer', 'content_length' => 'integer', 'structure_changed' => 'boolean', 'observed_at' => 'datetime']; }
    public function source(): BelongsTo { return $this->belongsTo(Source::class); }
}
