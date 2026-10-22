<?php

namespace Tests\Feature;

use App\Contracts\Collectors\CollectorInterface;
use App\DTOs\CandidateDTO;
use App\Enums\IncidentStatus;
use App\Enums\LocationAccuracy;
use App\Enums\Severity;
use App\Enums\SourceType;
use App\Models\Category;
use App\Models\GeocodingCache;
use App\Models\Incident;
use App\Models\Source;
use App\Services\ScrapedIncidentIngestor;
use Carbon\CarbonImmutable;
use Database\Seeders\CategorySeeder;
use Database\Seeders\LocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class IntegrationPhaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_independent_press_and_official_evidence_become_one_corroborated_incident(): void
    {
        CarbonImmutable::setTestNow('2026-08-19 20:00:00 America/Asuncion');
        config()->set('alertapy.ingestion.local_rules_publish', true);
        $this->seed([CategorySeeder::class, LocationSeeder::class]);
        GeocodingCache::query()->create([
            'query' => 'Asunción', 'normalized_query' => 'asuncion', 'latitude' => -25.2867, 'longitude' => -57.3333,
            'precision' => LocationAccuracy::City, 'provider' => 'test',
        ]);
        $press = $this->source('medio-a', SourceType::Press, 'medio-a.test');
        $official = $this->source('oficial-a', SourceType::Official, 'oficial-a.test');
        $publishedAt = CarbonImmutable::now()->subHour();

        app(ScrapedIncidentIngestor::class)->ingest($press, $this->collector(new CandidateDTO(
            sourceSlug: $press->slug,
            sourceType: SourceType::Press,
            externalIdentifier: 'press-1',
            title: 'Incendio afecta depósito del barrio San Vicente en Asunción',
            originalUrl: 'https://medio-a.test/incendio-asuncion',
            content: 'Bomberos trabajan en un incendio de depósito en Asunción.',
            publishedAt: $publishedAt,
            detectedLocation: 'Asunción',
            metadata: ['category_slug' => 'fire', 'city' => 'Asunción', 'department' => 'Asunción', 'location_text' => 'Asunción'],
        )));
        app(ScrapedIncidentIngestor::class)->ingest($official, $this->collector(new CandidateDTO(
            sourceSlug: $official->slug,
            sourceType: SourceType::Official,
            externalIdentifier: 'official-1',
            title: 'Incendio en depósito del barrio San Vicente de Asunción',
            originalUrl: 'https://oficial-a.test/incendio-asuncion',
            content: 'Intervención oficial por incendio de depósito en Asunción.',
            publishedAt: $publishedAt->addMinutes(20),
            detectedLocation: 'Asunción',
            metadata: ['category_slug' => 'fire', 'city' => 'Asunción', 'department' => 'Asunción', 'location_text' => 'Asunción', 'representation_type' => 'line'],
        )));

        $this->assertDatabaseCount('incidents', 1);
        $this->assertDatabaseCount('incident_sources', 2);
        $incident = Incident::query()->firstOrFail();
        $this->assertSame('corroborated', $incident->verification_level);
        $this->assertSame(SourceType::Official, $incident->source_type);
        $this->assertSame('point', $incident->representation_type, 'No debe inventar una línea sin GeoJSON real.');
        $this->assertNull($incident->geometry_geojson);
    }

    public function test_radius_filter_uses_real_distance_and_layers_are_discoverable(): void
    {
        $this->seed(CategorySeeder::class);
        $category = Category::query()->where('slug', 'emergency')->firstOrFail();
        $this->incident($category, 'Cerca', -25.2867, -57.3333);
        $this->incident($category, 'Lejos', -25.45, -57.55);

        $this->getJson('/api/incidents?period=7d&near_lat=-25.2867&near_lng=-57.3333&radius_km=5')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Cerca')
            ->assertJsonPath('data.0.representation.type', 'point');
        $this->getJson('/api/layers')
            ->assertOk()
            ->assertJsonPath('data.representations.2', 'line')
            ->assertJsonPath('data.representations.3', 'polygon');
    }

    public function test_coverage_admin_is_disabled_without_token_and_protected_with_basic_auth(): void
    {
        $this->get('/admin/coverage')->assertNotFound();
        config()->set('alertapy.admin_token', 'secret-test');

        $this->get('/admin/coverage')->assertUnauthorized();
        $this->withBasicAuth('admin', 'secret-test')
            ->get('/admin/coverage')
            ->assertOk()
            ->assertSee('Cobertura territorial')
            ->assertSee('Salud de conectores');
    }

    private function collector(CandidateDTO $candidate): CollectorInterface
    {
        return new class($candidate) implements CollectorInterface
        {
            public function __construct(private readonly CandidateDTO $candidate) {}

            public function collect(Source $source): Collection
            {
                return collect([$this->candidate]);
            }
        };
    }

    private function source(string $slug, SourceType $type, string $domain): Source
    {
        return Source::query()->create([
            'name' => $slug, 'slug' => $slug, 'type' => $type, 'base_url' => "https://{$domain}/", 'domain' => $domain,
            'enabled' => true, 'polling_minutes' => 10,
        ]);
    }

    private function incident(Category $category, string $title, float $latitude, float $longitude): Incident
    {
        return Incident::query()->create([
            'category_id' => $category->id, 'title' => $title, 'summary' => $title, 'source_type' => SourceType::Official,
            'verification_level' => 'official', 'status' => IncidentStatus::Active, 'severity' => Severity::Medium,
            'latitude' => $latitude, 'longitude' => $longitude, 'location_accuracy' => LocationAccuracy::Exact,
            'location_text' => $title, 'starts_at' => now(), 'published_at' => now(),
        ]);
    }
}
