<?php

namespace App\Console\Commands;

use App\Models\CriticalFacility;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class SyncCriticalInfrastructureCommand extends Command
{
    protected $signature = 'alertapy:sync-critical-infrastructure';
    protected $description = 'Sincroniza infraestructura crítica georreferenciada desde el Geoportal oficial del INE';

    public function handle(): int
    {
        $stored = 0;
        foreach ((array) config('alertapy.infrastructure.layers') as $kind => $layer) {
            $start = 0;
            do {
                $response = Http::acceptJson()->timeout(120)->retry(2, 1000)->get((string) config('alertapy.infrastructure.wfs_endpoint'), [
                    'service' => 'WFS', 'version' => '2.0.0', 'request' => 'GetFeature', 'typeNames' => $layer,
                    'outputFormat' => 'application/json', 'srsName' => 'EPSG:4326', 'count' => 5000, 'startIndex' => $start,
                ]);
                if ($response->failed()) {
                    $this->error("El INE respondió HTTP {$response->status()} para {$kind}.");
                    return self::FAILURE;
                }
                $features = $response->json('features', []);
                foreach ($features as $feature) {
                    $coordinates = $feature['geometry']['coordinates'] ?? [];
                    $properties = $feature['properties'] ?? [];
                    if (! isset($coordinates[0], $coordinates[1]) || ! is_numeric($coordinates[0]) || ! is_numeric($coordinates[1])) continue;
                    CriticalFacility::query()->updateOrCreate(
                        ['kind' => $kind, 'external_id' => (string) ($feature['id'] ?? $properties['ogc_fid'] ?? '')],
                        [
                            'name' => trim((string) ($properties['nombref'] ?? $properties['descref'] ?? 'Sin nombre')),
                            'category' => trim((string) ($properties['desc_categ'] ?? $properties['desc_class'] ?? $properties['descref'] ?? '')) ?: null,
                            'department' => trim((string) ($properties['dptodesc'] ?? '')) ?: null,
                            'district' => trim((string) ($properties['distdesc'] ?? '')) ?: null,
                            'neighborhood' => trim((string) ($properties['barlocdesc'] ?? '')) ?: null,
                            'latitude' => (float) $coordinates[1], 'longitude' => (float) $coordinates[0],
                            'source_name' => 'Instituto Nacional de Estadística',
                            'source_url' => (string) config('alertapy.infrastructure.source_url'), 'synced_at' => now(),
                        ],
                    );
                    $stored++;
                }
                $start += count($features);
            } while (count($features) === 5000);
        }
        $this->info("Infraestructura INE sincronizada: {$stored} registros procesados.");
        return self::SUCCESS;
    }
}
