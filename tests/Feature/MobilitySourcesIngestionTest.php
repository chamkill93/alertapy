<?php

namespace Tests\Feature;

use App\Enums\LocationAccuracy;
use App\Models\GeocodingCache;
use App\Models\Source;
use Carbon\CarbonImmutable;
use Database\Seeders\CategorySeeder;
use Database\Seeders\LocationSeeder;
use Database\Seeders\SourceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MobilitySourcesIngestionTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_migraciones_publishes_a_geolocated_border_change_idempotently(): void
    {
        CarbonImmutable::setTestNow('2026-08-19 23:00:00 America/Asuncion');
        Cache::clear();
        $this->seed([CategorySeeder::class, SourceSeeder::class, LocationSeeder::class]);
        GeocodingCache::query()->create([
            'query' => 'Puente de la Integración, Presidente Franco, Paraguay',
            'normalized_query' => 'puente de la integracion, presidente franco, paraguay',
            'latitude' => -25.5922,
            'longitude' => -54.6034,
            'precision' => LocationAccuracy::Approximate,
            'provider' => 'test',
        ]);
        Http::fake([
            'migraciones.gov.py/noticias/' => Http::response('<main><article>
                <h2><a href="https://migraciones.gov.py/nuevo-horario-integracion/">Nuevo horario para el Puente de la Integración</a></h2>
                <time>19 de agosto de 2026</time>
            </article></main>'),
            'migraciones.gov.py/nuevo-horario-integracion/' => Http::response('<main><p>El nuevo cronograma modifica el horario del Puente de la Integración.</p></main>'),
        ]);

        $this->artisan('alertapy:scrape migraciones --force --days=7')->assertSuccessful();

        $this->assertDatabaseCount('incidents', 1);
        $this->assertDatabaseHas('incidents', [
            'city' => 'Presidente Franco',
            'department' => 'Alto Paraná',
            'border_status' => 'MODIFIED_OPERATION',
            'source_type' => 'official',
        ]);
        $this->getJson('/api/incidents?period=7d')
            ->assertOk()
            ->assertJsonPath('data.0.category.slug', 'borders')
            ->assertJsonPath('data.0.border_status', 'MODIFIED_OPERATION');

        Source::query()->where('slug', 'migraciones')->update(['last_run_at' => null]);
        $this->artisan('alertapy:scrape migraciones --force --days=7')->assertSuccessful();
        $this->assertDatabaseCount('incidents', 1);
        $this->assertDatabaseCount('incident_sources', 1);
    }
}
