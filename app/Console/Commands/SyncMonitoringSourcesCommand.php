<?php

namespace App\Console\Commands;

use App\Models\Source;
use App\Models\SourceRun;
use App\Services\SourceCommandRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

class SyncMonitoringSourcesCommand extends Command
{
    protected $signature = 'alertapy:sync-monitoring {source=mades-air-quality} {--force : Ignora la frecuencia configurada}';

    protected $description = 'Sincroniza estaciones, mediciones y alertas oficiales.';

    public function handle(SourceCommandRegistry $registry): int
    {
        $slug = (string) $this->argument('source');
        $collectors = $registry->monitoring();

        if (! isset($collectors[$slug])) {
            $this->error('Fuente de monitoreo no soportada.');

            return self::INVALID;
        }

        $source = Source::query()->where('slug', $slug)->first();
        if ($source === null || ! $source->enabled) {
            $this->warn($slug.': fuente ausente o desactivada por configuración.');

            return self::SUCCESS;
        }

        $lock = Cache::lock('alertapy:monitoring:'.$slug, SourceCommandRegistry::lockSeconds());
        if (! $lock->get()) {
            $this->warn($slug.': sincronización en curso.');

            return self::SUCCESS;
        }

        $started = now();
        $run = null;
        try {
            $source->refresh();
            if (! $this->option('force') && $source->last_run_at?->copy()->addMinutes($source->polling_minutes)->isFuture()) {
                $this->line($slug.': todavía no corresponde consultar.');

                return self::SUCCESS;
            }

            $run = SourceRun::query()->create(['source_id' => $source->id, 'started_at' => $started, 'status' => 'running']);
            $source->forceFill(['last_run_at' => $started])->save();
            $collector = app($collectors[$slug]);
            $stats = $slug === 'dmh-cap' ? $collector->sync() : $collector->sync($source);
            $received = $stats['received'] ?? $stats['stations'];
            $stored = $stats['stored'] ?? $stats['measurements'];
            $duration = (int) $started->diffInMilliseconds(now());
            $source->forceFill([
                'last_success_at' => now(),
                'last_change_at' => $stored > 0 ? now() : $source->last_change_at,
                'last_error' => null, 'last_error_type' => null, 'consecutive_errors' => 0,
                'last_duration_ms' => $duration, 'items_seen' => $received, 'items_new' => $stored,
                'last_new_item_at' => $stored > 0 ? now() : $source->last_new_item_at,
            ])->save();
            $run->forceFill([
                'finished_at' => now(), 'status' => 'success',
                'discovered' => $received, 'duration_ms' => $duration,
            ])->save();
            $this->info($source->name.': '.$received.' elementos recibidos, '.$stored.' guardados.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $duration = (int) $started->diffInMilliseconds(now());
            $error = mb_substr($exception->getMessage(), 0, 1900);
            $source->forceFill([
                'last_error' => $error, 'last_error_type' => 'MONITORING_ERROR',
                'last_duration_ms' => $duration,
                'consecutive_errors' => min(65535, $source->consecutive_errors + 1),
            ])->save();
            $run?->forceFill(['finished_at' => now(), 'status' => 'error', 'error' => $error, 'duration_ms' => $duration])->save();
            $this->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            $lock->release();
        }
    }
}
