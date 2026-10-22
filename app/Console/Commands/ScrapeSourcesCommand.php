<?php

namespace App\Console\Commands;

use App\Contracts\Collectors\CollectorInterface;
use App\Models\Source;
use App\Services\ScrapedIncidentIngestor;
use App\Services\SourceCommandRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

class ScrapeSourcesCommand extends Command
{
    protected $signature = 'alertapy:scrape
        {source=all : Fuente configurada o all}
        {--force : Ignora la frecuencia configurada}
        {--days= : Recupera el historial de los últimos N días (1 a 30)}
        {--archive= : Archivo JSON capturado desde el navegador para importar ANDE}';

    protected $description = 'Recolecta fuentes reales y publica en el mapa únicamente acontecimientos geolocalizables.';

    public function handle(ScrapedIncidentIngestor $ingestor): int
    {
        $requested = (string) $this->argument('source');
        $collectors = $this->collectors();
        $days = $this->option('days');
        $archive = $this->option('archive');

        if ($requested !== 'all' && ! isset($collectors[$requested])) {
            $this->error('Fuente no soportada. Usá: '.implode(', ', array_keys($collectors)).' o all.');

            return self::INVALID;
        }

        if ($days !== null) {
            $days = filter_var($days, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1, 'max_range' => 30],
            ]);

            if ($days === false) {
                $this->error('La opción --days debe ser un número entero entre 1 y 30.');

                return self::INVALID;
            }

            foreach (array_keys($collectors) as $slug) {
                config()->set("alertapy.sources.{$slug}.lookback_days", $days);
            }
            $this->line("Recuperación histórica de {$days} días.");
        }

        if ($archive !== null) {
            if ($requested !== 'ande') {
                $this->error('La opción --archive solo puede utilizarse con la fuente ande.');

                return self::INVALID;
            }

            config()->set('alertapy.sources.ande.browser_archive_path', (string) $archive);
        }

        $selected = $requested === 'all' ? $collectors : [$requested => $collectors[$requested]];
        $lock = Cache::lock('alertapy:scrape-sources', SourceCommandRegistry::lockSeconds());

        if (! $lock->get()) {
            $this->warn('Ya existe una recolección en curso.');

            return self::SUCCESS;
        }

        $failures = 0;

        try {
            foreach ($selected as $slug => $collector) {
                $source = Source::query()->where('slug', $slug)->first();

                if ($source === null || ! $source->enabled) {
                    $this->line("{$slug}: fuente ausente o desactivada.");

                    continue;
                }

                if (! $this->option('force') && $source->last_run_at?->copy()->addMinutes($source->polling_minutes)->isFuture()) {
                    $this->line("{$source->name}: todavía no corresponde consultar.");

                    continue;
                }

                try {
                    $stats = $ingestor->ingest($source, $collector);
                    $this->info(sprintf(
                        '%s: %d detectados, %d publicados, %d fusionados, %d sin ubicación, %d pendientes de decisión, %d sin cambios.',
                        $source->name,
                        $stats['discovered'],
                        $stats['published'],
                        $stats['merged'],
                        $stats['waiting_location'],
                        $stats['pending_decision'],
                        $stats['unchanged'],
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

    /** @return array<string, CollectorInterface> */
    private function collectors(): array
    {
        return array_map(fn (string $class): CollectorInterface => app($class), app(SourceCommandRegistry::class)->scrapers());
    }
}
