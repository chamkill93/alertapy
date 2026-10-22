<?php

namespace App\Console\Commands;

use App\Services\DataRetentionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class PruneDataCommand extends Command
{
    protected $signature = 'alertapy:prune-data {--dry-run : Muestra los registros vencidos sin eliminar datos ni fotos}';

    protected $description = 'Elimina contenido y registros operativos de más de 7 días.';

    public function handle(DataRetentionService $retention): int
    {
        $lock = Cache::lock('alertapy:data-retention', 300);
        if (! $lock->get()) {
            $this->warn('Ya existe una purga de retención en ejecución.');

            return self::SUCCESS;
        }

        try {
            $result = $retention->prune((bool) $this->option('dry-run'));
            $this->info(($result['dry_run'] ? 'Simulación' : 'Retención aplicada').': contenido anterior a '.$result['cutoff']);
            $rows = [];
            foreach ($result['records'] as $table => $count) {
                $rows[] = [$table, $count];
            }
            $rows[] = ['fotos comunitarias', $result['photos']];
            $this->table(['Tipo', $result['dry_run'] ? 'Por eliminar' : 'Eliminados'], $rows);
        } finally {
            $lock->release();
        }

        return self::SUCCESS;
    }
}
