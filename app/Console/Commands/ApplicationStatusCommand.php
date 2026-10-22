<?php

namespace App\Console\Commands;

use App\Models\CriticalFacility;
use App\Models\Incident;
use App\Models\MonitoringStation;
use App\Models\Road;
use App\Models\Source;
use App\Services\SchedulerHealthService;
use App\Services\SourceHealthService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class ApplicationStatusCommand extends Command
{
    protected $signature = 'alertapy:status';
    protected $description = 'Muestra un diagnóstico operativo seguro de AlertaPY.';

    public function handle(SchedulerHealthService $scheduler, SourceHealthService $sourceHealth): int
    {
        try { DB::select('select 1'); $database = 'OK'; } catch (Throwable) { $database = 'ERROR'; }
        $enabled = Source::query()->where('enabled', true)->with('latestRun')->get();
        $healthy = $enabled->filter(fn (Source $source): bool => $sourceHealth->status($source)['status'] === 'HEALTHY')->count();
        $schedulerStatus = $scheduler->status(); $cycle = $schedulerStatus['last_cycle'];
        $rows = [
            ['Database',$database],
            ['Scheduler',$schedulerStatus['status']],
            ['Último ciclo',$cycle?->finished_at?->diffForHumans() ?? 'Nunca'],
            ['Fuentes saludables',"{$healthy}/{$enabled->count()}"],
            ['Incidencias activas',Incident::query()->current()->count()],
            ['Estaciones',MonitoringStation::query()->where('is_active', true)->count()],
            ['Red vial',Road::query()->active()->exists() ? 'OK' : 'SIN DATOS'],
            ['Hidrografía',is_file(public_path('data/map-layers/rivers.geojson')) ? 'OK' : 'SIN DATOS'],
            ['Infraestructura',CriticalFacility::query()->count()],
        ];
        foreach (['ande','ultimahora'] as $slug) { $source=$enabled->firstWhere('slug',$slug); if($source) $rows[]=[strtoupper($slug),$sourceHealth->status($source)['status']]; }
        $this->info('AlertaPY Status'); $this->table(['Componente','Estado'], $rows);
        return $database === 'OK' ? self::SUCCESS : self::FAILURE;
    }
}
