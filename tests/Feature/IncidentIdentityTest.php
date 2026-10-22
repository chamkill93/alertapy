<?php

namespace Tests\Feature;

use App\Contracts\Collectors\CollectorInterface;
use App\DTOs\CandidateDTO;
use App\Enums\SourceType;
use App\Models\Incident;
use App\Models\Source;
use App\Services\ScrapedIncidentIngestor;
use Carbon\CarbonImmutable;
use Database\Seeders\CategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IncidentIdentityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-22 20:00:00 America/Asuncion');
        config()->set('alertapy.ingestion.local_rules_publish', true);
        Http::preventStrayRequests();
        $this->seed(CategorySeeder::class);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_unrelated_events_in_the_same_city_and_time_are_kept_separate(): void
    {
        $source = $this->source('prensa-a');
        $this->ingest($source, $this->candidate($source, 'one', 'Incendio afecta depósito del barrio San Vicente en Asunción'));
        $this->ingest($source, $this->candidate($source, 'two', 'Incendio afecta depósito del barrio Trinidad en Asunción'));

        $this->assertDatabaseCount('incidents', 2);
        $this->assertDatabaseCount('incident_sources', 2);
    }

    public function test_different_sources_and_a_shared_city_centroid_do_not_prove_corroboration(): void
    {
        $first = $this->source('prensa-a');
        $second = $this->source('prensa-b');
        $this->ingest($first, $this->candidate($first, 'one', 'Incendio afecta depósito del barrio San Vicente en Asunción'));
        $this->ingest($second, $this->candidate($second, 'two', 'Incendio afecta depósito del barrio Trinidad en Asunción'));

        $this->assertDatabaseCount('incidents', 2);
        $this->assertSame(0, Incident::query()->where('verification_level', 'corroborated')->count());
    }

    public function test_distinct_articles_about_the_same_event_are_corroborated(): void
    {
        $first = $this->source('prensa-a');
        $second = $this->source('prensa-b');
        $this->ingest($first, $this->candidate($first, 'one', 'Incendio afecta depósito del barrio San Vicente en Asunción'));
        $this->ingest($second, $this->candidate($second, 'two', 'Incendio en depósito del barrio San Vicente de Asunción'));

        $this->assertDatabaseCount('incidents', 1);
        $this->assertDatabaseCount('incident_sources', 2);
        $this->assertSame('corroborated', Incident::query()->sole()->verification_level);
    }

    public function test_shared_utility_words_do_not_merge_outages_in_different_neighborhoods(): void
    {
        foreach (['water' => 'agua', 'electricity' => 'energía'] as $category => $utility) {
            $first = $this->source("{$category}-prensa-a");
            $second = $this->source("{$category}-prensa-b");
            $this->ingest($first, $this->candidate($first, 'one', "Corte de {$utility} en barrio San Juan de Asunción", category: $category));
            $this->ingest($second, $this->candidate($second, 'two', "Corte de {$utility} en barrio San Pedro de Asunción", category: $category));
        }

        $this->assertDatabaseCount('incidents', 4);
        $this->assertDatabaseCount('incident_sources', 4);
        $this->assertSame(0, Incident::query()->where('verification_level', 'corroborated')->count());
    }

    public function test_distinct_utility_reports_with_shared_event_identity_still_corroborate(): void
    {
        $reports = [
            'water' => [
                'Rotura en tubería principal del barrio San Juan provoca corte de agua en Asunción',
                'Corte del suministro de agua por rotura en tubería principal de San Juan en Asunción',
            ],
            'electricity' => [
                'Corte de energía en alimentador SAN123 de Asunción',
                'Interrupción del suministro eléctrico en alimentador SAN123 de Asunción',
            ],
        ];
        foreach ($reports as $category => [$firstTitle, $secondTitle]) {
            $first = $this->source("{$category}-prensa-a");
            $second = $this->source("{$category}-prensa-b");
            $this->ingest($first, $this->candidate($first, 'one', $firstTitle, category: $category));
            $this->ingest($second, $this->candidate($second, 'two', $secondTitle, category: $category));
        }

        $this->assertDatabaseCount('incidents', 2);
        $this->assertDatabaseCount('incident_sources', 4);
        $this->assertSame(2, Incident::query()->where('verification_level', 'corroborated')->count());
    }

    public function test_a_shared_road_kilometer_does_not_merge_different_events(): void
    {
        $this->artisan('alertapy:roads:import', ['file' => base_path('tests/Fixtures/roads/mopc-test-network.geojson')])->assertSuccessful();
        $source = $this->source('prensa-a');
        $this->ingest($source, $this->candidate($source, 'one', 'Incendio afecta depósito del barrio San Vicente en Ruta PY02 km 25', nativeCoordinates: false));
        $this->ingest($source, $this->candidate($source, 'two', 'Incendio afecta depósito del barrio Trinidad en Ruta PY02 km 25', nativeCoordinates: false));

        $this->assertDatabaseCount('incidents', 2);
        $this->assertSame(2, Incident::query()->where('road_code', 'PY02')->where('road_kilometer', 25)->count());
    }

    public function test_an_updated_article_preserves_its_incident_identity_even_if_the_headline_changes(): void
    {
        $source = $this->source('prensa-a');
        $this->ingest($source, $this->candidate($source, 'one', 'Incendio afecta depósito del barrio San Vicente en Asunción'));
        $publicId = Incident::query()->sole()->public_id;
        $this->ingest($source, $this->candidate($source, 'one', 'Bomberos combaten llamas en fábrica de Asunción'));

        $this->assertDatabaseCount('incidents', 1);
        $this->assertDatabaseCount('incident_sources', 1);
        $this->assertSame($publicId, Incident::query()->sole()->public_id);
        $this->assertDatabaseHas('incident_updates', ['title' => 'Bomberos combaten llamas en fábrica de Asunción']);
    }

    private function source(string $slug): Source
    {
        return Source::query()->create([
            'name' => $slug, 'slug' => $slug, 'type' => SourceType::Press,
            'base_url' => "https://{$slug}.test/", 'domain' => "{$slug}.test",
            'enabled' => true, 'polling_minutes' => 10,
        ]);
    }

    private function candidate(Source $source, string $identifier, string $title, bool $nativeCoordinates = true, string $category = 'fire'): CandidateDTO
    {
        return new CandidateDTO(
            sourceSlug: $source->slug,
            sourceType: SourceType::Press,
            externalIdentifier: $identifier,
            title: $title,
            originalUrl: "https://{$source->domain}/{$identifier}",
            content: $title,
            publishedAt: CarbonImmutable::now()->subHour(),
            detectedLocation: 'Asunción',
            latitude: $nativeCoordinates ? -25.2867 : null,
            longitude: $nativeCoordinates ? -57.6333 : null,
            metadata: ['category_slug' => $category, 'city' => 'Asunción', 'department' => 'Asunción', 'location_text' => 'Asunción', 'location_accuracy' => 'city'],
        );
    }

    private function ingest(Source $source, CandidateDTO $candidate): void
    {
        app(ScrapedIncidentIngestor::class)->ingest($source, new class($candidate) implements CollectorInterface
        {
            public function __construct(private readonly CandidateDTO $candidate) {}

            public function collect(Source $source): Collection
            {
                return collect([$this->candidate]);
            }
        });
    }
}
