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

class DiscoveryIngestionTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_gdelt_archives_original_evidence_for_decision_without_publishing_an_incident(): void
    {
        CarbonImmutable::setTestNow('2026-08-19 23:00:00 America/Asuncion');
        Cache::clear();
        $this->seed([CategorySeeder::class, SourceSeeder::class, LocationSeeder::class]);
        Source::query()->where('slug', 'gdelt')->update(['enabled' => true]);
        GeocodingCache::query()->create([
            'query' => 'Asunción',
            'normalized_query' => 'asuncion',
            'latitude' => -25.2867,
            'longitude' => -57.3333,
            'precision' => LocationAccuracy::City,
            'provider' => 'test',
        ]);
        Http::fake(['api.gdeltproject.org/*' => Http::response(['articles' => [[
            'url' => 'https://medio.example/incendio-asuncion',
            'title' => 'Incendio afecta un depósito en Asunción',
            'seendate' => '20260819T220000Z',
        ]]])]);

        $this->artisan('alertapy:discover gdelt --force')->assertSuccessful();

        $this->assertDatabaseCount('raw_items', 1);
        $this->assertDatabaseHas('raw_items', ['original_url' => 'https://medio.example/incendio-asuncion']);
        $this->assertDatabaseHas('content_candidates', ['source_type' => 'discovery', 'status' => 'pending_ai', 'ai_status' => 'pending']);
        $this->assertDatabaseCount('incidents', 0);

        $this->artisan('alertapy:discover gdelt --force')->assertSuccessful();
        $this->assertDatabaseCount('raw_items', 1);
        $this->assertDatabaseCount('content_candidates', 1);
        $this->assertDatabaseCount('incidents', 0);
    }

    public function test_gdelt_searches_utilities_health_rivers_and_environment_as_reviewable_candidates(): void
    {
        CarbonImmutable::setTestNow('2026-08-19 23:00:00 America/Asuncion');
        Cache::clear();
        $this->seed([CategorySeeder::class, SourceSeeder::class, LocationSeeder::class]);
        Source::query()->where('slug', 'gdelt')->update(['enabled' => true]);
        GeocodingCache::query()->create([
            'query' => 'Asunción',
            'normalized_query' => 'asuncion',
            'latitude' => -25.2867,
            'longitude' => -57.3333,
            'precision' => LocationAccuracy::City,
            'provider' => 'test',
        ]);

        $sequence = Http::sequence()
            ->push(['articles' => [[
                'url' => 'https://medio.example/corte-asuncion',
                'title' => 'Corte de energía deja sin luz a barrios de Asunción',
                'seendate' => '20260819T220000Z',
            ]]])
            ->push(['articles' => [[
                'url' => 'https://medio.example/brote-san-pedro',
                'title' => 'Dengue: confirman brote en San Pedro',
                'seendate' => '20260819T215000Z',
            ]]])
            ->push(['articles' => [[
                'url' => 'https://medio.example/crecida-pilar',
                'title' => 'Crecida del río Paraguay amenaza viviendas en Pilar',
                'seendate' => '20260819T214000Z',
            ]]])
            ->push(['articles' => [[
                'url' => 'https://medio.example/humo-alto-parana',
                'title' => 'Humo y contaminación preocupan a vecinos de Alto Paraná',
                'seendate' => '20260819T213000Z',
            ], [
                'url' => 'https://medio.example/ruta-bloqueada-caaguazu',
                'title' => 'Ruta bloqueada por derrumbe en Caaguazú',
                'seendate' => '20260819T212000Z',
            ]]])
            ->push(['articles' => []]);

        Http::fake([
            'api.gdeltproject.org/*' => $sequence,
            '*' => Http::response([]),
        ]);

        $this->artisan('alertapy:discover gdelt --force')->assertSuccessful();

        $this->assertDatabaseCount('raw_items', 5);
        $this->assertDatabaseCount('content_candidates', 5);
        $this->assertDatabaseCount('incidents', 0);
        foreach ([
            'https://medio.example/corte-asuncion',
            'https://medio.example/brote-san-pedro',
            'https://medio.example/crecida-pilar',
            'https://medio.example/humo-alto-parana',
            'https://medio.example/ruta-bloqueada-caaguazu',
        ] as $url) {
            $this->assertDatabaseHas('raw_items', ['original_url' => $url]);
        }
        $this->assertDatabaseHas('content_candidates', ['source_type' => 'discovery', 'status' => 'pending_ai']);
        $gdeltRequests = Http::recorded(fn ($request, $response): bool => str_contains($request->url(), 'api.gdeltproject.org'))->count();
        $this->assertSame(5, $gdeltRequests);
    }

}
