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
}
