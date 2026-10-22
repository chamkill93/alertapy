<?php

namespace App\Console\Commands;

use App\Services\IncidentDeduplicator;
use Illuminate\Console\Command;

class DeduplicateIncidentsCommand extends Command
{
    protected $signature = 'alertapy:deduplicate';

    protected $description = 'Consolida marcadores repetidos sin eliminar su evidencia original.';

    public function handle(IncidentDeduplicator $deduplicator): int
    {
        $stats = $deduplicator->consolidateOfficialElectricity();
        $this->info(sprintf(
            '%d grupos consolidados; %d marcadores repetidos fusionados.',
            $stats['groups'],
            $stats['merged'],
        ));

        return self::SUCCESS;
    }
}
