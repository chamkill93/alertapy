<?php

namespace App\Services;

use App\Models\SchedulerCycle;

class SchedulerHealthService
{
    /** @return array<string, mixed> */
    public function status(): array
    {
        $latest = SchedulerCycle::query()->latest('started_at')->latest('id')->first();
        $staleMinutes = max(5, (int) config('alertapy.scheduler_stale_minutes', 15));
        $status = match (true) {
            $latest === null => 'MISSING',
            $latest->finished_at === null && $latest->started_at->lt(now()->subMinutes($staleMinutes)) => 'STALE',
            $latest->status === 'failed' => 'FAILED',
            $latest->finished_at === null => 'RUNNING',
            $latest->finished_at->lt(now()->subMinutes($staleMinutes)) => 'STALE',
            default => 'HEALTHY',
        };

        return ['status' => $status, 'stale_minutes' => $staleMinutes, 'last_cycle' => $latest, 'is_healthy' => $status === 'HEALTHY'];
    }
}
