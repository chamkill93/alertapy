<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Source;
use App\Services\SourceHealthService;
use App\Services\SchedulerHealthService;
use Illuminate\Http\JsonResponse;

class SourceStatusController extends Controller
{
    public function __invoke(SourceHealthService $healthService, SchedulerHealthService $schedulerHealth): JsonResponse
    {
        $sources = Source::query()
            ->with('latestRun')
            ->orderBy('type')
            ->orderBy('name')
            ->get()
            ->map(function (Source $source) use ($healthService): array {
                $status = match (true) {
                    ! $source->enabled => 'disabled',
                    $source->last_run_at === null => 'pending_first_run',
                    $source->last_error !== null && ($source->last_success_at === null || $source->last_run_at->greaterThan($source->last_success_at)) => 'error',
                    default => 'ok',
                };
                $health = $healthService->status($source);

                return [
                    'name' => $source->name,
                    'slug' => $source->slug,
                    'type' => $source->type->value,
                    'ingestion_type' => $source->ingestion_type->value,
                    'georef_mode' => $source->georef_mode->value,
                    'trust_level' => $source->trust_level->value,
                    'enabled' => $source->enabled,
                    'status' => $status,
                    'health' => $health['status'],
                    // Raw request errors can contain private URLs or tokens.
                    'health_reason' => $health['reason'] === null ? null
                        : (preg_match('/^[A-Z][A-Z0-9_]*$/', $health['reason']) === 1 ? $health['reason'] : 'SOURCE_REQUEST_FAILED'),
                    'polling_minutes' => $source->polling_minutes,
                    'last_run_at' => $source->last_run_at?->toIso8601String(),
                    'last_success_at' => $source->last_success_at?->toIso8601String(),
                    'last_change_at' => $source->last_change_at?->toIso8601String(),
                    'last_http_status' => $source->last_http_status,
                    'last_discovered' => $source->latestRun?->discovered,
                    'last_candidates' => $source->latestRun?->created_candidates,
                    'consecutive_errors' => $source->consecutive_errors,
                    'next_run_at' => $source->enabled
                        ? ($source->last_run_at?->addMinutes($source->polling_minutes) ?? now())->toIso8601String()
                        : null,
                    'inactive_reason' => $source->enabled
                        ? null
                        : ($source->configuration_json['inactive_reason'] ?? null),
                ];
            });

        $scheduler = $schedulerHealth->status();
        $cycle = $scheduler['last_cycle'];

        return response()->json([
            'data' => $sources,
            'meta' => [
                'scheduler_status' => $scheduler['status'],
                'last_cycle_started_at' => $cycle?->started_at?->toIso8601String(),
                'last_cycle_finished_at' => $cycle?->finished_at?->toIso8601String(),
                'sources_enabled' => $sources->where('enabled', true)->count(),
                'sources_healthy' => $sources->where('health', 'HEALTHY')->count(),
            ],
        ]);
    }
}
