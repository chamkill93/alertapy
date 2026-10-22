<?php

namespace App\Console\Commands;

use App\Services\RoadMilestoneImporter;
use Illuminate\Console\Command;
use Throwable;

class ImportRoadMilestonesCommand extends Command
{
    protected $signature = 'alertapy:roads:import-milestones {file} {--dry-run} {--max-distance=60}';
    protected $description = 'Asocia hitos kilométricos físicos a rutas PY por proximidad inequívoca.';
    public function handle(RoadMilestoneImporter $importer): int
    {
        try { $stats = $importer->import((string) $this->argument('file'), (bool) $this->option('dry-run'), max(10,min(200,(float) $this->option('max-distance')))); $this->table(['Métrica','Valor'],collect($stats)->map(fn ($value,$key)=>[$key,$value])->values()->all()); return self::SUCCESS; }
        catch (Throwable $error) { $this->error($error->getMessage()); return self::FAILURE; }
    }
}
