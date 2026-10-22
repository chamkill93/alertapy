<?php

namespace Tests\Feature;

use App\Enums\IncidentStatus;
use App\Enums\LocationAccuracy;
use App\Enums\Severity;
use App\Enums\SourceType;
use App\Models\Category;
use App\Models\CriticalFacility;
use App\Models\Incident;
use App\Models\Source;
use App\Models\WeatherAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TerritorialIntelligencePhasesTest extends TestCase
{
    use RefreshDatabase;

    public function test_phase_12_syncs_and_counts_nearby_critical_infrastructure(): void
    {
        Http::fake(['*' => Http::response(['features' => [[
            'id' => 'facility-1', 'geometry' => ['coordinates' => [-57.6, -25.3]],
            'properties' => ['nombref' => 'Centro de referencia', 'desc_categ' => 'Hospital', 'dptodesc' => 'CENTRAL', 'distdesc' => 'SAN LORENZO'],
        ]]])]);
        $this->artisan('alertapy:sync-critical-infrastructure')->assertSuccessful();
        $this->assertDatabaseCount('critical_facilities', 3);

        $this->getJson('/api/nearby/infrastructure?lat=-25.3&lng=-57.6&radius_km=2')
            ->assertOk()->assertJsonPath('data.total', 3)
            ->assertJsonPath('data.categories.0.count', 1)
            ->assertJsonPath('data.categories.1.count', 1)
            ->assertJsonPath('data.categories.2.count', 1);
    }

    public function test_infrastructure_is_exposed_as_independent_filtered_map_layers(): void
    {
        foreach ([['health', 'Hospital'], ['police', 'Comisaría']] as [$kind, $name]) {
            CriticalFacility::query()->create(['external_id' => $kind.'-1', 'kind' => $kind, 'name' => $name, 'latitude' => -25.3, 'longitude' => -57.6, 'source_name' => 'INE', 'source_url' => 'https://ine.gov.py', 'synced_at' => now()]);
        }

        $this->getJson('/api/infrastructure?bbox=-25.4,-57.7,-25.2,-57.5&kinds=health')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.kind', 'health')
            ->assertJsonPath('data.0.name', 'Hospital')
            ->assertJsonPath('data.0.is_cluster', false);

        $this->getJson('/api/infrastructure?bbox=-30,-65,-17,-52&kinds=health,police&zoom=6')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.aggregated', true)
            ->assertJsonPath('data.0.is_cluster', true);

        $page = $this->get('/')->assertOk();
        $page->assertSee('Infraestructura')
            ->assertSee('Capas apagadas.')
            ->assertSee('Estaciones')
            ->assertSee('Estaciones DMH')
            ->assertSee('id="monitoring-layer-status"', false)
            ->assertSee('id="infrastructure-layer-status"', false);
        $this->assertStringNotContainsString('value="health" checked', $page->getContent());
        $this->assertStringNotContainsString('value="education" checked', $page->getContent());
        $this->assertStringNotContainsString('value="police" checked', $page->getContent());
        $this->assertStringNotContainsString('value="dmh" checked', $page->getContent());
    }

    public function test_phase_13_syncs_fresh_dmh_station_measurements_and_cap_alerts(): void
    {
        $stationSource = $this->source('DMH estaciones', 'dmh-automatic-stations', SourceType::Sensor);
        $capSource = $this->source('DMH CAP', 'dmh-cap', SourceType::Official);
        $xml = '<alert><identifier>cap-1</identifier><sent>'.now()->toIso8601String().'</sent><info><event>Tormenta</event><headline>Alerta de tormenta</headline><severity>Severe</severity><urgency>Immediate</urgency><certainty>Likely</certainty><onset>'.now()->subMinute()->toIso8601String().'</onset><expires>'.now()->addHour()->toIso8601String().'</expires><area><areaDesc>Central</areaDesc><polygon>-25.4,-57.7 -25.2,-57.7 -25.2,-57.5 -25.4,-57.5 -25.4,-57.7</polygon></area></info></alert>';
        Http::fake(function ($request) use ($xml) {
            if (str_contains($request->url(), 'data.json')) {
                return Http::response(['estaciones' => ['86001' => [
                    'metadatos' => ['nombre' => 'Estación Central', 'ciudad' => 'San Lorenzo', 'departamento' => 'Central', 'estado' => 'activo', 'latitud' => -25.3, 'longitud' => -57.6],
                    'tiene_datos' => 'si', 'ultima_observacion' => ['fecha' => ['utc' => now()->toIso8601String()], 'temperatura_aire' => ['valor' => 31.8, 'unidad_medida' => '°C'], 'velocidad_viento' => ['valor' => 11.7, 'unidad_medida' => 'm/s'], 'precipitacion' => ['valor' => 38, 'unidad_medida' => 'mm']],
                ]]]);
            }
            return Http::response(['features' => [['properties' => ['id' => 'message-1', 'content' => ['value' => base64_encode($xml)]]]]]);
        });
        $this->artisan('alertapy:sync-monitoring dmh-automatic-stations')->assertSuccessful();
        $this->artisan('alertapy:sync-monitoring dmh-cap')->assertSuccessful();
        $this->assertDatabaseHas('monitoring_stations', ['source_id' => $stationSource->id, 'kind' => 'weather', 'is_collecting_data' => true]);
        $this->assertDatabaseHas('station_measurements', ['parameter' => 'temperature', 'value' => 31.8]);
        $this->assertDatabaseHas('weather_alerts', ['external_id' => 'cap-1', 'event' => 'Tormenta']);
        $this->assertTrue($capSource->fresh()->last_success_at !== null);
    }

    public function test_phases_14_and_15_expose_related_climate_context_and_zone_summary(): void
    {
        $weatherCategory = $this->category('Inundaciones y clima', 'weather_flood', 'weather');
        $trafficCategory = $this->category('Accidentes y tránsito', 'traffic', 'traffic');
        $weather = $this->incident($weatherCategory, 'Lluvia intensa', -25.30, -57.60);
        $traffic = $this->incident($trafficCategory, 'Calle bloqueada', -25.305, -57.605);
        CriticalFacility::query()->create(['external_id' => 'health-1', 'kind' => 'health', 'name' => 'Hospital cercano', 'latitude' => -25.304, 'longitude' => -57.604, 'source_name' => 'INE', 'source_url' => 'https://ine.gov.py', 'synced_at' => now()]);
        WeatherAlert::query()->create(['external_id' => 'zone-alert', 'event' => 'Tormenta', 'headline' => 'Alerta vigente', 'severity' => 'Severe', 'geometry_geojson' => ['type' => 'Polygon', 'coordinates' => [[[-57.7,-25.4],[-57.5,-25.4],[-57.5,-25.2],[-57.7,-25.2],[-57.7,-25.4]]]], 'expires_at' => now()->addHour(), 'source_url' => 'https://meteorologia.gov.py', 'synced_at' => now()]);

        $this->getJson("/api/incidents/{$weather->public_id}")->assertOk()
            ->assertJsonPath('data.climate_context.related_groups.0.count', 1)
            ->assertJsonPath('data.nearby_infrastructure.total', 1)
            ->assertJsonPath('data.climate_context.disclaimer', 'Coincidencia temporal y territorial. AlertaPY no afirma una relación causal entre estos eventos.');

        $this->getJson('/api/nearby/summary?lat=-25.3&lng=-57.6&radius_km=5')->assertOk()
            ->assertJsonPath('data.incident_count', 2)
            ->assertJsonPath('data.weather_alerts.0.headline', 'Alerta vigente')
            ->assertJsonPath('data.infrastructure.total', 1);
    }

    private function source(string $name, string $slug, SourceType $type): Source
    {
        return Source::query()->create(['name' => $name, 'slug' => $slug, 'type' => $type, 'base_url' => 'https://example.com', 'enabled' => true]);
    }

    private function category(string $name, string $slug, string $icon): Category
    {
        return Category::query()->create(['name' => $name, 'slug' => $slug, 'icon' => $icon, 'color' => '#0877e8', 'enabled' => true, 'sort_order' => 1]);
    }

    private function incident(Category $category, string $title, float $lat, float $lng): Incident
    {
        return Incident::query()->create(['category_id' => $category->id, 'title' => $title, 'summary' => $title, 'source_type' => SourceType::Official, 'verification_level' => 'official', 'status' => IncidentStatus::Active, 'severity' => Severity::Medium, 'latitude' => $lat, 'longitude' => $lng, 'location_accuracy' => LocationAccuracy::Exact, 'department' => 'Central', 'city' => 'San Lorenzo', 'location_text' => 'San Lorenzo', 'published_at' => now()->subMinutes(10), 'expires_at' => now()->addHours(2)]);
    }
}
