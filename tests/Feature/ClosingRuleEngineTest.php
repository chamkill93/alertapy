<?php

namespace Tests\Feature;

use App\Contracts\Collectors\CollectorInterface;
use App\DTOs\CandidateDTO;
use App\Enums\IncidentStatus;
use App\Enums\LocationAccuracy;
use App\Enums\Severity;
use App\Enums\SourceType;
use App\Models\Category;
use App\Models\Incident;
use App\Models\IncidentSource;
use App\Models\RawItem;
use App\Models\Source;
use App\Services\ClosingRuleEngine;
use App\Services\CorroborationService;
use App\Services\ScrapedIncidentIngestor;
use Carbon\CarbonImmutable;
use Database\Seeders\CategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ClosingRuleEngineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->seed(CategorySeeder::class);
    }

    public function test_negated_or_future_restoration_does_not_mean_the_service_was_closed(): void
    {
        $engine = new ClosingRuleEngine;

        foreach ([
            'El servicio será restablecido al finalizar los trabajos.',
            'Se espera que el suministro sea restablecido esta tarde.',
            'El servicio podría quedar normalizado luego de la reparación.',
            'El servicio no fue restablecido en Luque.',
            'El suministro aún no ha sido normalizado.',
            'Servicio no restablecido en alimentador SAN123.',
            'La zona continúa sin servicio normal.',
            'Trabajan para el restablecimiento del suministro de agua.',
            'El restablecimiento del servicio no fue confirmado.',
            'No confirmaron el restablecimiento del suministro.',
            'El servicio fue parcialmente restablecido en Luque.',
            'Normalizan el suministro de forma parcial.',
            'Confirmaron el restablecimiento parcial del servicio.',
            'Restablecimiento parcial del suministro de agua confirmado.',
            'Servicio normalizado por sectores en Luque.',
        ] as $text) {
            $this->assertFalse($engine->containsClosingLanguage($text), $text);
        }
    }

    public function test_actual_restoration_remains_a_closure_in_participles_verbs_and_completed_announcements(): void
    {
        $engine = new ClosingRuleEngine;

        foreach ([
            'Servicio normalizado en alimentador SAN123.',
            'El servicio fue restablecido en Luque.',
            'ESSAP restablece el suministro de agua potable en Luque.',
            'Restablecen el suministro eléctrico en Luque.',
            'ANDE restableció el suministro eléctrico.',
            'Restablecieron el suministro eléctrico en el barrio.',
            'ESSAP normaliza el suministro de agua.',
            'Normalizan el servicio de agua potable.',
            'Normalizó el suministro de agua.',
            'Normalizaron el suministro en el barrio.',
            'Confirmaron el restablecimiento del suministro.',
            'Se completó la normalización del suministro.',
            'Restablecimiento del servicio de agua confirmado.',
            'No hubo heridos; el servicio fue restablecido.',
            'El servicio no fue restablecido ayer, pero hoy quedó normalizado.',
            'Ayer hubo reposición parcial; hoy el servicio fue restablecido por completo.',
        ] as $text) {
            $this->assertTrue($engine->containsClosingLanguage($text), $text);
        }
    }

    public function test_an_outage_with_a_future_restoration_promise_is_published_instead_of_held_as_a_closure(): void
    {
        $source = $this->source();
        $stats = app(ScrapedIncidentIngestor::class)->ingest($source, $this->collector(
            $source, 'electricity', 'Corte de electricidad en alimentador SAN123. El servicio será restablecido al finalizar los trabajos.',
        ));

        $this->assertSame(1, $stats['published']);
        $this->assertSame(0, $stats['pending_decision']);
        $this->assertDatabaseHas('content_candidates', ['status' => 'published', 'ai_decision' => 'PUBLISH']);
        $this->assertDatabaseMissing('incident_updates', ['event_type' => 'resolved']);
    }

    public function test_a_negated_restoration_does_not_close_the_identified_service(): void
    {
        $incident = $this->incident('electricity', 'Corte de electricidad en alimentador SAN123');
        $source = $this->source();
        app(ScrapedIncidentIngestor::class)->ingest($source, $this->collector(
            $source, 'electricity', 'Servicio no fue restablecido en alimentador SAN123',
        ));

        $this->assertSame(IncidentStatus::Active, $incident->fresh()->status);
        $this->assertNull($incident->fresh()->resolved_at);
        $this->assertDatabaseMissing('incident_updates', ['incident_id' => $incident->id, 'event_type' => 'resolved']);
    }

    public function test_partial_restoration_does_not_close_the_identified_service(): void
    {
        $incident = $this->incident('electricity', 'Corte de electricidad en alimentador SAN123');
        $source = $this->source();
        app(ScrapedIncidentIngestor::class)->ingest($source, $this->collector(
            $source, 'electricity', 'Servicio parcialmente restablecido en alimentador SAN123',
        ));

        $this->assertSame(IncidentStatus::Active, $incident->fresh()->status);
        $this->assertNull($incident->fresh()->resolved_at);
        $this->assertDatabaseMissing('incident_updates', ['incident_id' => $incident->id, 'event_type' => 'resolved']);
    }

    public function test_closure_identifies_its_event_instead_of_the_latest_event_in_the_city(): void
    {
        $south = $this->incident('fire', 'Incendio en fábrica Sur', ['starts_at' => now()->subHours(2)]);
        $this->incident('fire', 'Incendio en depósito Norte');
        $engine = new ClosingRuleEngine;

        $this->assertSame($south->id, $engine->findMatching($south->category_id, 'Capiatá', 'Central', now(), 'Incendio controlado en fábrica Sur')?->id);
        $this->assertNull($engine->findMatching($south->category_id, 'Capiatá', 'Central', now(), 'Incendio controlado en fábrica Norte'));
        $this->assertNull($engine->findMatching($south->category_id, 'Capiatá', 'Central', now(), 'Incendio controlado'));
        $this->assertNull($engine->findMatching($south->category_id, null, null, now(), 'Incendio controlado en fábrica Sur'));
    }

    public function test_ambiguous_matching_events_remain_pending(): void
    {
        $first = $this->incident('fire', 'Incendio en fábrica Sur', ['starts_at' => now()->subHours(2)]);
        $this->incident('fire', 'Incendio en fábrica Sur');

        $this->assertNull((new ClosingRuleEngine)->findMatching($first->category_id, 'Capiatá', 'Central', now(), 'Incendio controlado en fábrica Sur'));
    }

    public function test_closing_keeps_temporal_category_and_territorial_guards(): void
    {
        $incident = $this->incident('electricity', 'Corte de electricidad en alimentador SAN123', ['starts_at' => now()->subDays(8)]);
        $engine = new ClosingRuleEngine;
        $title = 'Servicio normalizado en alimentador SAN123';

        $this->assertNull($engine->findMatching($incident->category_id, 'Capiatá', 'Central', now(), $title));
        $incident->update(['starts_at' => now()->subHour()]);
        $this->assertNull($engine->findMatching($incident->category_id, 'Encarnación', 'Itapúa', now(), $title));
        $this->assertNull($engine->findMatching(Category::query()->where('slug', 'water')->value('id'), 'Capiatá', 'Central', now(), $title));
        $this->assertSame($incident->id, $engine->findMatching($incident->category_id, 'Capiatá', 'Central', now(), $title)?->id);
    }

    public function test_reused_archived_item_identifies_its_event_even_with_a_generic_closing_title(): void
    {
        $source = $this->source();
        $incident = $this->incident('electricity', 'Servicio eléctrico interrumpido');
        $raw = RawItem::query()->create([
            'source_id' => $source->id, 'external_identifier' => 'outage-123',
            'original_url' => 'https://official.test/outage/123', 'title' => $incident->title,
            'raw_content' => 'Evidence', 'content_hash' => hash('sha256', 'outage-123'), 'detected_at' => now()->subHour(),
        ]);
        IncidentSource::query()->create([
            'incident_id' => $incident->id, 'source_id' => $source->id, 'raw_item_id' => $raw->id,
            'original_title' => $incident->title, 'original_url' => $raw->original_url, 'detected_at' => now()->subHour(),
        ]);

        $this->assertSame($incident->id, (new ClosingRuleEngine)->findMatching($incident->category_id, 'Capiatá', 'Central', now(), 'Servicio normalizado', null, $raw->id)?->id);
    }

    public function test_ingestor_cannot_bypass_missing_closure_identity_through_general_deduplication(): void
    {
        $incident = $this->incident('fire', 'Incendio en depósito Norte');
        $source = $this->source();
        $this->mock(CorroborationService::class)->shouldNotReceive('findMatch');

        $stats = app(ScrapedIncidentIngestor::class)->ingest($source, $this->collector($source, 'fire', 'Incendio controlado en fábrica Sur'));

        $this->assertSame(1, $stats['pending_decision']);
        $this->assertSame(IncidentStatus::Active, $incident->fresh()->status);
        $this->assertDatabaseCount('incidents', 1);
        $this->assertDatabaseHas('content_candidates', ['status' => 'pending_ai', 'ai_decision' => 'CLOSE']);
    }

    public function test_ingestor_closes_only_the_identified_electricity_service(): void
    {
        $identified = $this->incident('electricity', 'Corte de electricidad en alimentador SAN123');
        $other = $this->incident('electricity', 'Corte de electricidad en alimentador NOR456');
        $source = $this->source();

        $stats = app(ScrapedIncidentIngestor::class)->ingest($source, $this->collector($source, 'electricity', 'Servicio normalizado en alimentador SAN123'));

        $this->assertSame(1, $stats['merged']);
        $this->assertSame(IncidentStatus::Finalized, $identified->fresh()->status);
        $this->assertSame(IncidentStatus::Active, $other->fresh()->status);
        $this->assertDatabaseHas('incident_updates', ['incident_id' => $identified->id, 'event_type' => 'resolved']);
    }

    private function collector(Source $source, string $category, string $title): CollectorInterface
    {
        $candidate = new CandidateDTO(
            $source->slug, SourceType::Official, 'closing-1', $title, 'https://official.test/closing-1', $title,
            CarbonImmutable::now(), 'Capiatá', -25.3, -57.6,
            ['category_slug' => $category, 'city' => 'Capiatá', 'department' => 'Central', 'location_text' => 'Capiatá'],
        );

        return new class($candidate) implements CollectorInterface
        {
            public function __construct(private readonly CandidateDTO $candidate) {}

            public function collect(Source $source): Collection
            {
                return collect([$this->candidate]);
            }
        };
    }

    private function source(): Source
    {
        return Source::query()->create([
            'name' => 'Oficial', 'slug' => 'official-test', 'type' => SourceType::Official,
            'base_url' => 'https://official.test/', 'domain' => 'official.test', 'enabled' => true, 'polling_minutes' => 5,
        ]);
    }

    private function incident(string $category, string $title, array $overrides = []): Incident
    {
        return Incident::query()->create([
            'category_id' => Category::query()->where('slug', $category)->value('id'),
            'title' => $title, 'summary' => $title, 'source_type' => SourceType::Official,
            'verification_level' => 'official', 'status' => IncidentStatus::Active, 'severity' => Severity::Medium,
            'latitude' => -25.3, 'longitude' => -57.6, 'location_accuracy' => LocationAccuracy::City,
            'location_text' => 'Capiatá', 'city' => 'Capiatá', 'department' => 'Central',
            'starts_at' => now()->subHour(), 'published_at' => now()->subHour(), ...$overrides,
        ]);
    }
}
