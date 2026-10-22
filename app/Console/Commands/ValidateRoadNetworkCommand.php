<?php

namespace App\Console\Commands;

use App\Models\Road;
use App\Models\RoadAlias;
use App\Models\RoadReferencePoint;
use Illuminate\Console\Command;

class ValidateRoadNetworkCommand extends Command
{
    protected $signature = 'alertapy:roads:validate';
    protected $description = 'Valida geometrías, segmentos, aliases y referencias de la red vial.';
    public function handle(): int
    {
        $errors = collect();
        Road::query()->active()->cursor()->each(function (Road $road) use ($errors): void {
            if ($road->normalized_code === '' || count($road->geometry_json['coordinates'] ?? []) < 2) $errors->push("Ruta {$road->id}: código o geometría inválida");
            $previous = null; foreach ($road->segments()->cursor() as $segment) { if ($road->geometry_type === 'LineString' && $previous && (abs($previous->end_lat - $segment->start_lat) > .00001 || abs($previous->end_lng - $segment->start_lng) > .00001)) $errors->push("{$road->code}: segmentos desconectados {$previous->sequence}/{$segment->sequence}"); if ($segment->segment_length_meters > config('alertapy.roads.max_segment_length_meters')) $errors->push("{$road->code}: salto imposible en segmento {$segment->sequence}"); $previous = $segment; }
            $lastKm = null; foreach ($road->referencePoints()->whereNotNull('kilometer')->cursor() as $point) { if ($lastKm !== null && $point->kilometer <= $lastKm) $errors->push("{$road->code}: progresivas inconsistentes"); if ($point->latitude < $road->bbox_south - .01 || $point->latitude > $road->bbox_north + .01 || $point->longitude < $road->bbox_west - .01 || $point->longitude > $road->bbox_east + .01) $errors->push("{$road->code}: referencia fuera del bbox"); $lastKm = $point->kilometer; }
            unset($road);
        });
        RoadAlias::query()->selectRaw('normalized_alias, count(distinct road_id) total')->groupBy('normalized_alias')->having('total', '>', 1)->pluck('normalized_alias')->each(fn ($alias) => $errors->push("Alias ambiguo: {$alias}"));
        if ($errors->isEmpty()) { $this->info('Red vial válida.'); return self::SUCCESS; }
        $errors->each(fn ($error) => $this->error($error)); return self::FAILURE;
    }
}
