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
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TemporalIngestionTest extends TestCase
{
    use RefreshDatabase;

    private Source $source;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-22 16:00:00 America/Asuncion'));
        Http::preventStrayRequests();
        config()->set('alertapy.ingestion.local_rules_publish', true);
        $this->seed(CategorySeeder::class);
        $this->source = Source::query()->create([
            'name' => 'Medio de prueba', 'slug' => 'temporal-test', 'type' => SourceType::Press,
            'base_url' => 'https://temporal.test', 'domain' => 'temporal.test', 'enabled' => true, 'polling_minutes' => 5,
        ]);
    }

    #[DataProvider('currentContexts')]
    public function test_contextual_dates_do_not_move_current_incidents_into_the_past_or_renew_on_reread(string $content, string $category, int $hours): void
    {
        $publishedAt = now()->subHour()->toImmutable();
        $candidate = $this->candidate($content, $publishedAt, ['category_slug' => $category]);
        $this->ingest($candidate);
        $incident = Incident::query()->sole();

        $this->assertNull($incident->occurred_at);
        $this->assertTrue($incident->starts_at->equalTo($publishedAt));
        $this->assertTrue($incident->expires_at->equalTo($publishedAt->addHours($hours)));
        $this->getJson('/api/incidents?period=now')->assertOk()->assertJsonCount(1, 'data');

        $this->travel(20)->minutes();
        $this->ingest($candidate);
        $this->assertDatabaseCount('incidents', 1);
        $this->assertTrue($incident->fresh()->expires_at->equalTo($publishedAt->addHours($hours)));
    }

    public static function currentContexts(): array
    {
        return [
            'rain accumulation' => ['Sistema de tormentas ingresó a Asunción. El acumulado de lluvias en la costanera alcanza 69 mm desde ayer.', 'weather_flood', 12],
            'article update time' => ['Incendio afecta depósito en Asunción. La noticia fue actualizada hace 30 horas.', 'fire', 18],
            'ongoing incident' => ['El incendio permanece activo en Asunción desde anoche.', 'fire', 18],
        ];
    }

    public function test_an_explicit_occurrence_determines_expiration_before_map_visibility(): void
    {
        $publishedAt = now()->subHour()->toImmutable();
        $this->ingest($this->candidate('El incendio ocurrió hace 30 horas en Asunción.', $publishedAt));
        $incident = Incident::query()->sole();
        $occurredAt = $publishedAt->subHours(30);

        $this->assertTrue($incident->occurred_at->equalTo($occurredAt));
        $this->assertTrue($incident->starts_at->equalTo($occurredAt));
        $this->assertTrue($incident->expires_at->equalTo($occurredAt->addHours(18)));
        $this->getJson('/api/incidents?period=now')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/incidents?period=7d')->assertOk()->assertJsonCount(1, 'data');
    }

    private function candidate(string $content, CarbonImmutable $publishedAt, array $metadata = []): CandidateDTO
    {
        $category = $metadata['category_slug'] ?? 'fire';
        $title = $category === 'weather_flood' ? 'Tormentas afectan Asunción' : 'Incendio afecta depósito en Asunción';

        return new CandidateDTO(
            $this->source->slug, SourceType::Press, 'temporal-story', $title, 'https://temporal.test/story',
            $content, $publishedAt, 'Asunción', -25.2867, -57.6333,
            ['category_slug' => $category, 'city' => 'Asunción', 'department' => 'Asunción', 'location_text' => 'Asunción', 'location_accuracy' => 'city', ...$metadata],
        );
    }

    private function ingest(CandidateDTO $candidate): void
    {
        $collector = new class($candidate) implements CollectorInterface {
            public function __construct(private CandidateDTO $candidate) {}
            public function collect(Source $source): Collection { return collect([$this->candidate]); }
        };

        app(ScrapedIncidentIngestor::class)->ingest($this->source, $collector);
    }
}
