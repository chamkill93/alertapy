<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SchedulerCycle extends Model
{
    protected $fillable = [
        'started_at', 'finished_at', 'status', 'duration_ms', 'sources_processed',
        'candidates_created', 'incidents_created', 'incidents_updated', 'duplicates_merged',
        'incidents_closed', 'geography_errors', 'error_summary',
    ];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'finished_at' => 'datetime'];
    }
}
