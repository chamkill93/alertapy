<?php

namespace App\Console\Commands;

use App\Models\TerritorialProfile;
use App\Models\Location;
use App\Models\TerritorialGeometry;
use App\Services\GeometryService;
use App\Services\TerritorialImpactService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class SyncIneTerritoriesCommand extends Command
{
    protected $signature = 'alertapy:sync-ine-territories';

    protected $description = 'Sincroniza población distrital del Censo 2022 desde el Geoportal oficial del INE';

    public function handle(TerritorialImpactService $impactService, GeometryService $geometryService): int
    {
        $endpoint = (string) config('alertapy.ine.wfs_endpoint');
        $sourceUrl = (string) config('alertapy.ine.source_url');

        $syncedAt = now();
        $stored = 0;
        $skipped = 0;
        $startIndex = 0;
        $pageSize = 20;
        $receivedAny = false;

        while (true) {
            $response = Http::acceptJson()
                ->timeout(120)
                ->retry(2, 1000)
                ->get($endpoint, [
                    'service' => 'WFS',
                    'version' => '2.0.0',
                    'request' => 'GetFeature',
                    'typeNames' => (string) config('alertapy.ine.district_layer'),
                    'outputFormat' => 'application/json',
                    'count' => $pageSize,
                    'startIndex' => $startIndex,
                ]);

            if ($response->failed()) {
                $this->error("El INE respondió HTTP {$response->status()} en la página iniciada en {$startIndex}.");

                return self::FAILURE;
            }

            $features = $response->json('features');

            if (! is_array($features) || $features === []) {
                if (! $receivedAny) {
                    $this->error('El INE no devolvió perfiles territoriales. Se conserva la copia local anterior.');

                    return self::FAILURE;
                }

                break;
            }
            $receivedAny = true;

            DB::transaction(function () use (
                $features,
                $impactService,
                $geometryService,
                $sourceUrl,
                $syncedAt,
                &$stored,
                &$skipped,
            ): void {
            foreach ($features as $feature) {
                $properties = $feature['properties'] ?? [];
                $departmentCode = trim((string) ($properties['cod_dep'] ?? ''));
                $districtCode = trim((string) ($properties['cod_dis'] ?? ''));
                $department = trim((string) ($properties['departamen'] ?? ''));
                $district = trim((string) ($properties['distrito'] ?? ''));
                $population = filter_var($properties['pob1'] ?? null, FILTER_VALIDATE_INT);

                if ($departmentCode === '' || $districtCode === '' || $department === ''
                    || $district === '' || $population === false || $population < 0) {
                    $skipped++;

                    continue;
                }

                TerritorialProfile::query()->updateOrCreate(
                    [
                        'ine_department_code' => $departmentCode,
                        'ine_district_code' => $districtCode,
                    ],
                    [
                        'level' => 'district',
                        'department' => $department,
                        'district' => $district,
                        'normalized_department' => $impactService->normalize($department),
                        'normalized_district' => $impactService->normalize($district),
                        'population' => $population,
                        'census_year' => 2022,
                        'source_name' => 'Instituto Nacional de Estadística',
                        'source_url' => $sourceUrl,
                        'synced_at' => $syncedAt,
                    ],
                );
                $geometry = $feature['geometry'] ?? null;
                if (is_array($geometry) && in_array($geometry['type'] ?? null, ['Polygon', 'MultiPolygon'], true)) {
                    $coordinates = $this->coordinatePairs($geometry['coordinates'] ?? []);
                    if (count($coordinates) >= 4) {
                        $location = Location::query()->firstOrCreate(['type' => 'district', 'normalized_name' => $impactService->normalize($district)], ['name' => $district]);
                        $bbox = $geometryService->bbox($coordinates);
                        TerritorialGeometry::query()->updateOrCreate(['location_id' => $location->id], ['geometry_type' => $geometry['type'], 'geometry_json' => $geometry, 'bbox_north' => $bbox['north'], 'bbox_south' => $bbox['south'], 'bbox_east' => $bbox['east'], 'bbox_west' => $bbox['west'], 'source' => 'Instituto Nacional de Estadística', 'source_date' => $syncedAt->toDateString()]);
                    }
                }
                $stored++;
            }
            });

            $received = count($features); unset($features, $response); gc_collect_cycles();
            if ($received < $pageSize) break;
            $startIndex += $received;
            $this->line("INE: {$stored} distritos procesados…");
        }

        $this->info("INE sincronizado: {$stored} distritos guardados, {$skipped} omitidos.");

        return self::SUCCESS;
    }

    private function coordinatePairs(array $coordinates): array
    {
        if (isset($coordinates[0], $coordinates[1]) && is_numeric($coordinates[0]) && is_numeric($coordinates[1])) return [[(float) $coordinates[0], (float) $coordinates[1]]];
        $pairs = []; foreach ($coordinates as $child) if (is_array($child)) $pairs = [...$pairs, ...$this->coordinatePairs($child)]; return $pairs;
    }
}
