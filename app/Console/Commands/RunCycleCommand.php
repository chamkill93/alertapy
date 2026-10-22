<?php

namespace App\Console\Commands;

use App\Models\ContentCandidate;
use App\Models\Incident;
use App\Models\IncidentUpdate;
use App\Models\SchedulerCycle;
use App\Models\Source;
use App\Models\SourceRun;
use App\Services\IncidentExpirationService;
use App\Services\DataRetentionService;
use App\Services\SourceCommandRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Throwable;

class RunCycleCommand extends Command
{
    protected $signature = 'alertapy:cycle';

    protected $description = 'Ejecuta fuentes vencidas con presupuesto entre fuentes y checkpoint para el próximo cron.';

    public function handle(IncidentExpirationService $expiration, SourceCommandRegistry $registry, DataRetentionService $retention): int
    {
        $lock = Cache::lock('alertapy:general-cycle', SourceCommandRegistry::lockSeconds());

        if (! $lock->get()) {
            $this->warn('Ya existe un ciclo general en ejecución.');

            return self::SUCCESS;
        }

        $cycle = null;
        $started = microtime(true);

        try {
            $cycle = SchedulerCycle::query()->create(['started_at' => now(), 'status' => 'running']);
            // Retention precedes counters so deletions do not mask new items.
            // It also runs when there are no due sources or collection fails.
            $retentionLock = Cache::lock('alertapy:data-retention', 300);
            if ($retentionLock->get()) {
                try {
                    $retentionResult = $retention->prune();
                    $this->line('Retención de 7 días: '.array_sum($retentionResult['records']).' registros y '.$retentionResult['photos'].' fotos eliminados.');
                } finally {
                    $retentionLock->release();
                }
            } else {
                $this->line('Otra purga de retención está en ejecución.');
            }
            $incidentCount = Incident::query()->count();
            $updateCount = IncidentUpdate::query()->count();
            $candidateCount = ContentCandidate::query()->count();
            // Expiration runs even when a slow source consumes the collection budget.
            $expirationResult = $expiration->expire(false, 500);
            $cycle->forceFill(['incidents_closed' => $expirationResult['expired']])->save();
            $result = $this->runDueSources($registry, $cycle, $started, $candidateCount);
            $cycle->forceFill([
                'finished_at' => now(),
                'status' => $result['failures'] === [] ? 'success' : 'failed',
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'candidates_created' => max(0, ContentCandidate::query()->count() - $candidateCount),
                'incidents_created' => max(0, Incident::query()->count() - $incidentCount),
                'incidents_updated' => max(0, IncidentUpdate::query()->count() - $updateCount),
                'geography_errors' => Incident::query()->whereNotNull('location_quality_flags')->where('location_quality_flags', '!=', '[]')->count(),
                'error_summary' => $result['failures'] === [] ? null : mb_substr('Fuentes con error: '.implode(', ', $result['failures']), 0, 1900),
            ])->save();

            return $result['failures'] === [] ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $exception) {
            $cycle?->forceFill([
                'finished_at' => now(),
                'status' => 'failed',
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'error_summary' => mb_substr($exception->getMessage(), 0, 1900),
            ])->save();
            throw $exception;
        } finally {
            $lock->release();
        }
    }

    /** @return array{failures: array<int, string>} */
    private function runDueSources(SourceCommandRegistry $registry, SchedulerCycle $cycle, float $started, int $candidateCount): array
    {
        $maximum = max(1, (int) config('alertapy.cycle.max_sources', 20));
        $maximumCandidates = max(1, (int) config('alertapy.cycle.max_candidates', 100));
        $maximumSeconds = max(1, (int) config('alertapy.cycle.max_seconds', 180));
        $cursor = (int) Cache::get('alertapy:cycle:source-cursor', 0);
        $commands = $registry->commands();
        $sources = Source::query()->where('enabled', true)->whereIn('slug', array_keys($commands))
            ->orderBy('id')->get()
            ->filter(fn (Source $source): bool => $source->last_run_at === null || $source->last_run_at->copy()->addMinutes($source->polling_minutes)->lte(now()));
        // Wrap within the same batch so a high cursor never starves earlier sources.
        $sources = $sources->where('id', '>', $cursor)->concat($sources->where('id', '<=', $cursor))->take($maximum);
        $failures = [];
        $processed = 0;

        foreach ($sources as $source) {
            // Cooperative limits: a running source completes before the checkpoint advances.
            if ((microtime(true) - $started) >= $maximumSeconds || ContentCandidate::query()->count() - $candidateCount >= $maximumCandidates) {
                $this->line('Presupuesto del ciclo alcanzado; las fuentes restantes continúan en el próximo ciclo.');
                break;
            }

            $sourceStarted = now();
            $previousRunId = (int) SourceRun::query()->where('source_id', $source->id)->max('id');
            try {
                $exit = Artisan::call($commands[$source->slug], ['source' => $source->slug]);
                $this->line(trim(Artisan::output()));
                if ($exit !== self::SUCCESS) {
                    $failures[] = $source->slug;
                }
            } catch (Throwable $exception) {
                $failures[] = $source->slug;
                $this->error($source->slug.': '.$exception->getMessage());
                // Ingestors record ordinary errors. Cover failures resolving a provider
                // before its ingestor can create a run as well.
                if (! SourceRun::query()->where('source_id', $source->id)->where('id', '>', $previousRunId)->exists()) {
                    SourceRun::query()->create([
                        'source_id' => $source->id, 'started_at' => $sourceStarted,
                        'finished_at' => now(), 'status' => 'error',
                        'error' => mb_substr($exception->getMessage(), 0, 1900),
                    ]);
                    $source->refresh()->forceFill([
                        'last_run_at' => $sourceStarted,
                        'last_error' => mb_substr($exception->getMessage(), 0, 1900),
                        'last_error_type' => 'COMMAND_ERROR',
                        'consecutive_errors' => min(65535, $source->consecutive_errors + 1),
                    ])->save();
                }
            } finally {
                $processed++;
                Cache::forever('alertapy:cycle:source-cursor', $source->id);
                $cycle->forceFill(['sources_processed' => $processed])->save();
            }
        }

        $this->info('Ciclo finalizado: '.$processed.' fuentes procesadas; '.count($failures).' errores.');

        return ['failures' => $failures];
    }
}
