<?php

namespace App\Console\Commands;

use App\Enums\LocationQuality;
use App\Models\Incident;
use App\Services\LocationQualityAnalyzer;
use Illuminate\Console\Command;

class GeographicQualityCommand extends Command
{
    protected $signature = 'alertapy:geo:quality {--active} {--historical} {--fix-safe} {--dry-run}';
    protected $description = 'Audita la calidad geográfica sin inventar coordenadas ni degradar ubicaciones.';

    public function handle(LocationQualityAnalyzer $analyzer): int
    {
        $query = Incident::query()->with(['category:id,slug,name','road:id,length_meters'])->orderBy('id');
        if ($this->option('active')) $query->current();
        if (! $this->option('historical') && ! $this->option('active')) $query->published();
        $rows = []; $updated = 0;
        $query->chunkById(250, function ($incidents) use ($analyzer, &$rows, &$updated): void {
            foreach ($incidents as $incident) {
                $result = $analyzer->analyze($incident);
                if ($result['flags'] !== [] || in_array($result['quality'], [LocationQuality::Low, LocationQuality::VeryLow, LocationQuality::Unknown], true)) {
                    $rows[] = [$incident->public_id, $incident->category->name, $incident->location_text, $result['method']->value, $result['quality']->value, implode(', ', $result['flags']) ?: 'LOW_ACCURACY'];
                }
                if ($this->option('fix-safe') && ! $this->option('dry-run')) {
                    $incident->forceFill(['location_method'=>$result['method'],'location_quality'=>$result['quality'],'location_quality_flags'=>$result['flags']])->save();
                    $updated++;
                }
            }
        });
        $this->table(['Incidente','Categoría','Ubicación','Método','Calidad','Flags'], array_slice($rows, 0, 100));
        $this->info(count($rows).' incidencia(s) requieren atención; '.$updated.' actualizada(s) de forma segura.'.($this->option('dry-run') ? ' DRY RUN.' : ''));
        return self::SUCCESS;
    }
}
