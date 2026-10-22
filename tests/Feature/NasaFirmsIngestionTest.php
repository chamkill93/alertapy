<?php

namespace Tests\Feature;

use App\Enums\IncidentStatus;
use App\Enums\LocationAccuracy;
use App\Enums\Severity;
use App\Enums\SourceType;
use App\Models\Category;
use App\Models\Incident;
use App\Models\IncidentSource;
use App\Models\Source;
use Carbon\CarbonImmutable;
use Database\Seeders\CategorySeeder;
use Database\Seeders\SourceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NasaFirmsIngestionTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_native_detection_is_merged_with_nearby_infona_evidence(): void
    {
        CarbonImmutable::setTestNow('2026-08-19 23:00:00 America/Asuncion');
        Cache::clear();
        config()->set('alertapy.sources.nasa-firms.enabled', true);
        config()->set('alertapy.sources.nasa-firms.map_key', 'test-key');
        config()->set('alertapy.sources.nasa-firms.bbox', '-62.7,-27.7,-54.2,-19.1');
        $this->seed([CategorySeeder::class, SourceSeeder::class]);

        $category = Category::query()->where('slug', 'fire')->firstOrFail();
        $infona = Source::query()->where('slug', 'infona')->firstOrFail();
        $incident = Incident::query()->create([
            'category_id' => $category->id,
            'title' => 'Aviso oficial de INFONA',
            'summary' => 'Foco reportado por INFONA.',
            'source_type' => SourceType::Official,
            'verification_level' => 'official',
            'status' => IncidentStatus::Active,
            'severity' => Severity::Medium,
            'latitude' => -23.4420,
            'longitude' => -58.5580,
            'location_accuracy' => LocationAccuracy::Approximate,
            'location_text' => 'Presidente Hayes',
            'starts_at' => now()->subHour(),
            'expires_at' => now()->addHours(17),
            'published_at' => now()->subHour(),
        ]);
        IncidentSource::query()->create([
            'incident_id' => $incident->id,
            'source_id' => $infona->id,
            'original_title' => 'Aviso oficial de INFONA',
            'original_url' => 'https://infona.gov.py/',
            'published_at' => now()->subHour(),
            'detected_at' => now()->subHour(),
        ]);
        Http::fake([
            'firms.modaps.eosdis.nasa.gov/api/area/csv/*' => Http::response(
                "latitude,longitude,bright_ti4,acq_date,acq_time,satellite,instrument,confidence\n-23.4400,-58.5600,342.1,2026-08-19,0130,N20,VIIRS,n",
                200,
                ['Content-Type' => 'text/csv'],
            ),
        ]);

        $this->artisan('alertapy:scrape nasa-firms --force')->assertSuccessful();

        $incident->refresh();
        $this->assertDatabaseCount('incidents', 1);
        $this->assertDatabaseCount('incident_sources', 2);
        $this->assertSame('corroborated', $incident->verification_level);
        $this->assertDatabaseHas('content_candidates', ['latitude' => -23.44, 'longitude' => -58.56, 'status' => 'published']);

        Source::query()->where('slug', 'nasa-firms')->update(['last_run_at' => null]);
        $this->artisan('alertapy:scrape nasa-firms --force')->assertSuccessful();
        $this->assertDatabaseCount('incident_sources', 2);
    }
}
