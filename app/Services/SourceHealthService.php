<?php

namespace App\Services;

use App\Models\Source;

class SourceHealthService
{
    public function status(Source $source): array
    {
        if (! $source->enabled) {
            $configured = ! str_contains((string) data_get($source->configuration_json, 'inactive_reason', ''), 'DISABLED_CONFIGURATION');

            return ['status' => $configured ? 'DISABLED' : 'DISABLED_CONFIGURATION', 'reason' => data_get($source->configuration_json, 'inactive_reason')];
        }
        $latest = $source->latestRun;
        $structureChanged = array_key_exists('recent_structure_changed', $source->getAttributes())
            ? (bool) $source->getAttribute('recent_structure_changed')
            : $source->parserSnapshots()->where('structure_changed', true)->where('observed_at', '>=', now()->subDay())->exists();
        $knownExternalBlock = in_array($source->last_error_type, ['ANTI_BOT_BLOCKED', 'DNS_RESOLUTION_ERROR'], true)
            || str_contains(strtolower((string) $source->last_error), 'anti-bot')
            || str_contains(strtolower((string) $source->last_error), 'resolve host');
        $staleMinutes = max((int) config('alertapy.scheduler_stale_minutes', 15), max(1, $source->polling_minutes) * 3);
        $stale = $source->last_success_at !== null && $source->last_success_at->lt(now()->subMinutes($staleMinutes));
        $status = match (true) {
            $knownExternalBlock => 'DEGRADED', $source->consecutive_errors >= 3 => 'FAILING', $structureChanged, in_array($source->last_http_status, [403, 429, 503], true), $source->last_error !== null, $source->last_success_at === null => 'DEGRADED', $stale => 'STALE', default => 'HEALTHY'
        };
        $reason = $structureChanged ? 'PARSER_STRUCTURE_CHANGED' : ($source->last_error_type ?: $source->last_error ?: ($stale ? 'LAST_SUCCESS_STALE' : ($source->last_success_at === null ? 'NEVER_SUCCEEDED' : null)));

        return ['status' => $status, 'reason' => $reason, 'error_type' => $source->last_error_type, 'http_status' => $source->last_http_status, 'last_attempt' => $source->last_run_at, 'last_success' => $source->last_success_at, 'last_change' => $source->last_change_at, 'last_new_item' => $source->last_new_item_at, 'items' => $source->items_seen ?: ($latest?->discovered ?? 0), 'items_new' => $source->items_new ?: ($latest?->created_candidates ?? 0), 'duration_ms' => $source->last_duration_ms ?: $latest?->duration_ms, 'consecutive_errors' => $source->consecutive_errors, 'next_run_at' => $source->last_run_at?->copy()->addMinutes($source->polling_minutes)];
    }
}
