<?php

namespace App\Console\Commands;

use App\Services\RoadDatasetImporter;
use Illuminate\Console\Command;
use Throwable;

class ImportRoadDatasetCommand extends Command
{
    protected $signature = 'alertapy:roads:import {file} {--dry-run} {--replace} {--dataset-name=} {--source-name=} {--source-url=} {--source-date=}';
    protected $description = 'Valida e importa una red vial local GeoJSON, KML, KMZ o CSV.';

    public function handle(RoadDatasetImporter $importer): int
    {
        try {
            $result = $importer->import((string) $this->argument('file'), ['dry_run' => (bool) $this->option('dry-run'), 'replace' => (bool) $this->option('replace'), 'dataset_name' => $this->option('dataset-name') ?: null, 'source_name' => $this->option('source-name') ?: null, 'source_url' => $this->option('source-url') ?: null, 'source_date' => $this->option('source-date') ?: null]);
            $this->table(['Métrica', 'Valor'], collect($result)->map(fn ($value, $key) => [$key, is_bool($value) ? ($value ? 'sí' : 'no') : $value])->values()->all());
            return ($result['valid_roads'] ?? 0) > 0 ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $error) { $this->error($error->getMessage()); return self::FAILURE; }
    }
}
