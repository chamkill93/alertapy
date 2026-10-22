<?php

namespace App\Console\Commands;

use App\Contracts\Discovery\DiscoveryProviderInterface;
use App\Models\Source;
use App\Services\DiscoveryIngestor;
use App\Services\SourceCommandRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

class DiscoverSourcesCommand extends Command
{
    protected $signature = 'alertapy:discover {source=all : Proveedor configurado o all} {--force : Ignora la frecuencia configurada}';

    protected $description = 'Archiva hallazgos de discovery/social para revisión, sin publicarlos automáticamente.';

    public function handle(DiscoveryIngestor $ingestor): int
    {
        $requested = (string) $this->argument('source');
        $providers = $this->providers();

        if ($requested !== 'all' && ! isset($providers[$requested])) {
            $this->error('Proveedor no soportado. Usá: '.implode(', ', array_keys($providers)).' o all.');

            return self::INVALID;
        }

        $selected = $requested === 'all' ? $providers : [$requested => $providers[$requested]];
        $lock = Cache::lock('alertapy:discover-sources', SourceCommandRegistry::lockSeconds());

        if (! $lock->get()) {
            $this->warn('Ya existe un ciclo de discovery en curso.');

            return self::SUCCESS;
        }

        $failures = 0;

        try {
            foreach ($selected as $slug => $provider) {
                $source = Source::query()->where('slug', $slug)->first();

                if ($source === null || ! $source->enabled) {
                    $this->line("{$slug}: proveedor ausente o desactivado por configuración.");

                    continue;
                }

                if (! $this->option('force') && $source->last_run_at?->copy()->addMinutes($source->polling_minutes)->isFuture()) {
                    $this->line("{$source->name}: todavía no corresponde consultar.");

                    continue;
                }

                try {
                    $stats = $ingestor->ingest($source, $provider);
                    $this->info(sprintf(
                        '%s: %d hallazgos, %d pendientes de decisión, %d sin ubicación, %d sin cambios y %d omitidos por fecha. Ninguno fue publicado automáticamente.',
                        $source->name,
                        $stats['discovered'],
                        $stats['queued'],
                        $stats['waiting_location'],
                        $stats['unchanged'],
                        $stats['ignored_date'],
                    ));
                } catch (Throwable $exception) {
                    $failures++;
                    $this->error("{$source->name}: {$exception->getMessage()}");
                }
            }
        } finally {
            $lock->release();
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** @return array<string, DiscoveryProviderInterface> */
    private function providers(): array
    {
        return array_map(fn (string $class): DiscoveryProviderInterface => app($class), app(SourceCommandRegistry::class)->discovery());
    }
}
