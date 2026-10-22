<?php

namespace App\Console\Commands;

use App\Services\LocationResolutionPipeline;
use Illuminate\Console\Command;

class LocateRoadTextCommand extends Command
{
    protected $signature = 'alertapy:locate {text}';
    protected $description = 'Prueba la resolución vial de un texto sin inventar coordenadas.';
    public function handle(LocationResolutionPipeline $pipeline): int
    {
        $result = $pipeline->resolve((string) $this->argument('text'));
        if ($result === null) { $this->warn('No se detectó una ruta paraguaya.'); return self::SUCCESS; }
        $this->table(['Campo', 'Valor'], [['Road', $result->roadCode], ['Kilometer', $result->kilometer], ['City', $result->city], ['Method', $result->resolutionMethod], ['Latitude', $result->latitude], ['Longitude', $result->longitude], ['Estimated error', $result->estimatedErrorMeters]]);
        return self::SUCCESS;
    }
}
