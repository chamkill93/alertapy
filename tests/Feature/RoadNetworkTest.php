<?php

namespace Tests\Feature;

use App\Models\Road;
use App\Models\Location;
use App\Models\TerritorialGeometry;
use App\Services\LocationResolutionPipeline;
use App\Services\RoadMilestoneImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoadNetworkTest extends TestCase
{
    use RefreshDatabase;

    public function test_geojson_import_dry_run_and_commit_build_segments_aliases_and_references(): void
    {
        $file = base_path('tests/Fixtures/roads/mopc-test-network.geojson');
        $this->artisan('alertapy:roads:import', ['file' => $file, '--dry-run' => true, '--dataset-name' => 'MOPC test'])->assertSuccessful();
        $this->assertDatabaseCount('road_datasets', 0);
        $this->artisan('alertapy:roads:import', ['file' => $file, '--dataset-name' => 'MOPC test', '--source-url' => 'https://mopc.gov.py/red-vial'])->assertSuccessful();
        $this->assertDatabaseCount('roads', 1); $this->assertDatabaseCount('road_segments', 2); $this->assertDatabaseCount('road_reference_points', 2);
        $this->assertDatabaseHas('road_aliases', ['normalized_alias' => 'PY02']);
        $this->artisan('alertapy:roads:validate')->assertSuccessful();
    }

    public function test_kml_and_csv_are_accepted_in_dry_run_without_mutating_the_database(): void
    {
        $this->artisan('alertapy:roads:import', ['file'=>base_path('tests/Fixtures/roads/mopc-test-network.kml'),'--dry-run'=>true])->assertSuccessful();
        $this->artisan('alertapy:roads:import', ['file'=>base_path('tests/Fixtures/roads/mopc-test-network.csv'),'--dry-run'=>true])->assertSuccessful();
        $this->assertDatabaseCount('road_datasets',0);
    }

    public function test_overpass_json_is_converted_to_local_road_features(): void
    {
        $this->artisan('alertapy:roads:import', ['file'=>base_path('tests/Fixtures/roads/openstreetmap-overpass-test.json'),'--dataset-name'=>'OSM test'])->assertSuccessful();
        $this->assertDatabaseHas('roads', ['normalized_code'=>'PY02','classification'=>'nacional']);
        $this->assertDatabaseHas('road_aliases', ['normalized_alias'=>'PY02']);
        $this->assertDatabaseHas('roads', ['normalized_code'=>'PY03','geometry_type'=>'MultiLineString']);
        $this->assertDatabaseCount('road_segments', 3);
    }

    public function test_official_and_interpolated_kilometers_follow_the_imported_geometry(): void
    {
        $this->artisan('alertapy:roads:import', ['file' => base_path('tests/Fixtures/roads/mopc-test-network.geojson')])->assertSuccessful(); $pipeline = app(LocationResolutionPipeline::class);
        $official = $pipeline->resolve('Accidente sobre Ruta PY02 km 25'); $this->assertSame('ROUTE_KM_OFFICIAL', $official->resolutionMethod); $this->assertEqualsWithDelta(-57.5, $official->longitude, .00001);
        $interpolated = $pipeline->resolve('Choque en Ruta 2, kilómetro 26,5'); $this->assertSame('ROUTE_KM_INTERPOLATED', $interpolated->resolutionMethod); $this->assertGreaterThan(-57.5, $interpolated->longitude); $this->assertLessThan(-57.4, $interpolated->longitude); $this->assertSame([25.0,30.0], $interpolated->evidence['reference_points']);
        $only = $pipeline->resolve('Accidente sobre PY02'); $this->assertSame('ROUTE_ONLY', $only->resolutionMethod); $this->assertNull($only->latitude);
    }

    public function test_road_apis_filter_by_bbox_and_resolve_search(): void
    {
        $this->artisan('alertapy:roads:import', ['file' => base_path('tests/Fixtures/roads/mopc-test-network.geojson')])->assertSuccessful(); $road = Road::query()->firstOrFail();
        $this->getJson('/api/roads?bbox=-25.5,-57.7,-25.2,-57.2')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'PY02');
        $this->getJson("/api/roads/{$road->id}/segments")->assertOk()->assertJsonCount(2, 'data');
        $this->getJson("/api/roads/{$road->id}/reference-points")->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/roads/resolve?query='.urlencode('PY02 km 26'))->assertOk()->assertJsonPath('data.resolutionMethod', 'ROUTE_KM_INTERPOLATED');
    }

    public function test_real_road_intersection_is_resolved_with_tolerance(): void
    {
        $this->artisan('alertapy:roads:import', ['file'=>base_path('tests/Fixtures/roads/mopc-test-intersection.geojson')])->assertSuccessful();
        $result = app(LocationResolutionPipeline::class)->resolve('Accidente en el cruce de la PY02 con PY03');
        $this->assertSame('ROUTE_INTERSECTION',$result->resolutionMethod); $this->assertEqualsWithDelta(-25.3,$result->latitude,.001); $this->assertEqualsWithDelta(-57.5,$result->longitude,.001);
    }

    public function test_observed_milestone_is_associated_only_when_it_is_close_to_one_road(): void
    {
        $this->artisan('alertapy:roads:import', ['file'=>base_path('tests/Fixtures/roads/openstreetmap-overpass-test.json')])->assertSuccessful();
        $stats = app(RoadMilestoneImporter::class)->import(base_path('tests/Fixtures/roads/milestones-test.json'));
        $this->assertSame(1,$stats['valid']); $this->assertSame(1,$stats['imported']); $this->assertSame(1,$stats['unmatched']);
        $this->assertDatabaseHas('road_reference_points',['reference_type'=>'KM_MARKER_OBSERVED','kilometer'=>12]);
        $resolved = app(LocationResolutionPipeline::class)->resolve('Accidente en PY02 km 12');
        $this->assertSame('ROUTE_KM_OBSERVED',$resolved->resolutionMethod); $this->assertLessThanOrEqual(75,$resolved->estimatedErrorMeters);
    }

    public function test_route_and_district_use_the_real_road_segment_inside_official_geometry(): void
    {
        $this->artisan('alertapy:roads:import', ['file'=>base_path('tests/Fixtures/roads/openstreetmap-overpass-test.json')])->assertSuccessful();
        $location = Location::query()->create(['type'=>'district','name'=>'Capiatá','normalized_name'=>'capiata','enabled'=>true]);
        TerritorialGeometry::query()->create(['location_id'=>$location->id,'geometry_type'=>'Polygon','geometry_json'=>['type'=>'Polygon','coordinates'=>[[[-57.58,-25.32],[-57.52,-25.32],[-57.52,-25.28],[-57.58,-25.28],[-57.58,-25.32]]]],'bbox_north'=>-25.28,'bbox_south'=>-25.32,'bbox_east'=>-57.52,'bbox_west'=>-57.58,'source'=>'INE','source_date'=>'2026-08-20']);
        $resolved = app(LocationResolutionPipeline::class)->resolve('Accidente sobre PY02 en Capiatá');
        $this->assertSame('ROUTE_LOCALITY',$resolved->resolutionMethod); $this->assertEqualsWithDelta(-25.3,$resolved->latitude,.001); $this->assertGreaterThan(-57.59,$resolved->longitude); $this->assertLessThan(-57.51,$resolved->longitude);
    }
}
