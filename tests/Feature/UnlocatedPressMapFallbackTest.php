<?php

namespace Tests\Feature;

use App\Contracts\Collectors\CollectorInterface;
use App\DTOs\CandidateDTO;
use App\Enums\SourceType;
use App\Models\Incident;
use App\Models\Source;
use App\Services\MapLocationFallback;
use App\Services\ScrapedIncidentIngestor;
use Carbon\CarbonImmutable;
use Database\Seeders\CategorySeeder;
use Database\Seeders\LocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class UnlocatedPressMapFallbackTest extends TestCase
{
    use RefreshDatabase;

    private Source $source;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-22 16:00:00 America/Asuncion'));
        Http::preventStrayRequests();
        $this->seed([CategorySeeder::class, LocationSeeder::class]);
        $this->source = Source::query()->create([
            'name' => 'Medio de prueba', 'slug' => 'unlocated-press-test', 'type' => SourceType::Press,
            'base_url' => 'https://press.test/', 'domain' => 'press.test', 'enabled' => true, 'polling_minutes' => 10,
        ]);
    }

    public function test_unknown_press_location_is_mapped_to_asuncion_without_claiming_that_department_or_nearby_context(): void
    {
        $candidate = new CandidateDTO(
            sourceSlug: $this->source->slug,
            sourceType: SourceType::Press,
            externalIdentifier: 'unknown-place-story',
            title: 'Choque deja dos vehículos dañados y tránsito lento',
            originalUrl: 'https://press.test/unknown-place-story',
            content: 'La noticia describe el choque, pero no permite identificar su localidad.',
            publishedAt: now()->subMinutes(10)->toImmutable(),
            detectedLocation: 'Asunción',
            metadata: [
                'category_slug' => 'traffic_accident',
                'location_is_fallback' => true,
                'location_text' => MapLocationFallback::LABEL,
                'location_accuracy' => 'approximate',
                'city' => null,
                'department' => null,
            ],
        );
        $collector = new class($candidate) implements CollectorInterface {
            public function __construct(private readonly CandidateDTO $candidate) {}

            public function collect(Source $source): Collection
            {
                return collect([$this->candidate]);
            }
        };

        $stats = app(ScrapedIncidentIngestor::class)->ingest($this->source, $collector);
        $incident = Incident::query()->sole();

        $this->assertSame(1, $stats['published']);
        $this->assertNull($incident->city);
        $this->assertNull($incident->department);
        $this->assertSame(MapLocationFallback::LABEL, $incident->location_text);
        $this->assertTrue($incident->usesReferenceLocation());
        $this->assertSame(MapLocationFallback::METHOD, data_get($incident->location_evidence_json, 'method'));
        $this->assertEqualsWithDelta(-25.2800459, $incident->latitude, 0.01);
        $this->assertEqualsWithDelta(-57.6343814, $incident->longitude, 0.01);

        $this->getJson('/api/incidents?period=24h&bbox=-25.5,-57.9,-25.1,-57.3')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.location.is_fallback', true)
            ->assertJsonPath('data.0.location.city', null)
            ->assertJsonPath('data.0.location.department', null);

        $this->getJson('/api/incidents?period=24h&city=Asunción')
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $this->getJson('/api/incidents?period=24h&near_lat=-25.2800459&near_lng=-57.6343814&radius_km=5')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->getJson('/api/incidents/'.$incident->public_id)
            ->assertOk()
            ->assertJsonPath('data.nearby_infrastructure', null)
            ->assertJsonPath('data.territorial_impact', null)
            ->assertJsonPath('data.climate_context', null)
            ->assertJsonPath('data.publication_explanation.reasons.1', 'Ubicación no informada; marcador de referencia en Asunción');
    }
}
