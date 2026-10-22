<?php

namespace Tests\Feature;

use App\Enums\IncidentStatus;
use App\Enums\LocationAccuracy;
use App\Enums\Severity;
use App\Enums\SourceType;
use App\Models\Category;
use App\Models\Incident;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IneTerritorialImpactTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_syncs_ine_district_population_and_exposes_it_in_incident_detail(): void
    {
        Http::fake([
            '*' => Http::response([
                'type' => 'FeatureCollection',
                'features' => [[
                    'type' => 'Feature',
                    'properties' => [
                        'cod_dep' => '11',
                        'departamen' => 'CENTRAL',
                        'cod_dis' => '01',
                        'distrito' => 'SAN LORENZO',
                        'pob1' => 225395,
                    ],
                ]],
            ]),
        ]);

        $this->artisan('alertapy:sync-ine-territories')
            ->expectsOutput('INE sincronizado: 1 distritos guardados, 0 omitidos.')
            ->assertSuccessful();

        $category = Category::query()->create([
            'name' => 'Electricidad',
            'slug' => 'electricity',
            'icon' => 'electricity',
            'color' => '#f5b400',
            'enabled' => true,
            'sort_order' => 1,
        ]);
        $incident = Incident::query()->create([
            'category_id' => $category->id,
            'title' => 'Corte programado',
            'summary' => 'Incidencia territorial de prueba.',
            'source_type' => SourceType::Official,
            'verification_level' => 'official',
            'status' => IncidentStatus::Active,
            'severity' => Severity::Medium,
            'latitude' => -25.34,
            'longitude' => -57.51,
            'location_accuracy' => LocationAccuracy::City,
            'department' => 'Central',
            'city' => 'San Lorenzo',
            'location_text' => 'San Lorenzo',
            'published_at' => now(),
            'expires_at' => now()->addHour(),
        ]);

        $this->getJson("/api/incidents/{$incident->public_id}")
            ->assertOk()
            ->assertJsonPath('data.territorial_impact.territory.district', 'SAN LORENZO')
            ->assertJsonPath('data.territorial_impact.population_reference', 225395)
            ->assertJsonPath('data.territorial_impact.census_year', 2022)
            ->assertJsonPath('data.territorial_impact.source.name', 'Instituto Nacional de Estadística')
            ->assertJsonPath(
                'data.territorial_impact.disclaimer',
                'Población censal del distrito de referencia; no equivale a personas afectadas.',
            );
    }

    public function test_it_returns_no_impact_when_there_is_no_matching_territorial_profile(): void
    {
        $category = Category::query()->create([
            'name' => 'Clima',
            'slug' => 'weather',
            'icon' => 'weather',
            'color' => '#0877e8',
            'enabled' => true,
            'sort_order' => 1,
        ]);
        $incident = Incident::query()->create([
            'category_id' => $category->id,
            'title' => 'Aviso meteorológico',
            'summary' => 'Incidencia sin perfil territorial.',
            'source_type' => SourceType::Official,
            'verification_level' => 'official',
            'status' => IncidentStatus::Active,
            'severity' => Severity::Medium,
            'latitude' => -22.35,
            'longitude' => -60.03,
            'location_accuracy' => LocationAccuracy::City,
            'department' => 'Boquerón',
            'city' => 'Filadelfia',
            'location_text' => 'Filadelfia',
            'published_at' => now(),
            'expires_at' => now()->addHour(),
        ]);

        $this->getJson("/api/incidents/{$incident->public_id}")
            ->assertOk()
            ->assertJsonPath('data.territorial_impact', null);
    }
}
