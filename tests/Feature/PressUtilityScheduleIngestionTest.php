<?php

namespace Tests\Feature;

use App\Contracts\Collectors\CollectorInterface;
use App\DTOs\CandidateDTO;
use App\Enums\IncidentStatus;
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

class PressUtilityScheduleIngestionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-22 12:00:00 America/Asuncion'));
        config()->set('alertapy.ingestion.local_rules_publish', true);
        Http::preventStrayRequests();
        $this->seed(CategorySeeder::class);
    }

    public function test_future_utility_schedule_preserves_publication_date_and_is_idempotent(): void
    {
        foreach (['electricity', 'water'] as $category) {
            $source = $this->source($category);
            $candidate = $this->candidate($source, $category, [
                'starts_at' => '2026-10-24T08:00:00-03:00',
                'ends_at' => '2026-10-24T12:00:00-03:00',
            ]);

            $this->assertSame(1, $this->ingest($source, $candidate)['published']);
            $incident = Incident::query()->whereHas('sources', fn ($query) => $query->where('source_id', $source->id))->sole();
            $this->assertSame(IncidentStatus::Scheduled, $incident->status);
            $this->assertSame('2026-10-20 09:00:00', $incident->published_at->toDateTimeString());
            $this->assertSame('2026-10-24 08:00:00', $incident->starts_at->toDateTimeString());
            $this->assertSame('2026-10-24 12:00:00', $incident->ends_at->toDateTimeString());
            $this->assertTrue($incident->expires_at->equalTo($incident->ends_at));
            $this->assertSame('2026-10-20 09:00:00', $incident->sources()->sole()->published_at->toDateTimeString());
            $originalDates = $incident->only(['published_at', 'starts_at', 'ends_at', 'expires_at', 'detected_at', 'first_seen_at', 'last_seen_at']);
            $originalUpdates = $incident->updates()->count();

            $this->travelTo(CarbonImmutable::parse('2026-10-23 12:00:00 America/Asuncion'));
            $stats = $this->ingest($source, $candidate);

            $this->assertSame(1, $stats['unchanged']);
            $this->assertSame(0, $stats['published']);
            $this->assertSame(0, $stats['merged']);
            $this->assertEquals($originalDates, $incident->fresh()->only(array_keys($originalDates)));
            $this->assertSame($originalUpdates, $incident->updates()->count());
            $this->assertSame(1, Incident::query()->whereHas('sources', fn ($query) => $query->where('source_id', $source->id))->count());
        }

        $this->assertDatabaseCount('incidents', 2);
        $this->assertDatabaseCount('raw_items', 2);
        $this->assertDatabaseCount('incident_sources', 2);
    }

    public function test_schedule_without_end_expires_relative_to_future_start(): void
    {
        $source = $this->source('water');
        $this->ingest($source, $this->candidate($source, 'water', ['starts_at' => '2026-10-24T08:00:00-03:00']));

        $incident = Incident::query()->sole();
        $this->assertSame(IncidentStatus::Scheduled, $incident->status);
        $this->assertNull($incident->ends_at);
        $this->assertTrue($incident->expires_at->equalTo($incident->starts_at->addDay()));
        $this->assertSame('2026-10-20 09:00:00', $incident->published_at->toDateTimeString());
    }

    public function test_utility_without_future_schedule_and_other_press_categories_remain_reported(): void
    {
        foreach ([
            ['electricity', []],
            ['water', ['starts_at' => '2026-10-21T08:00:00-03:00']],
            ['roads', ['starts_at' => '2026-10-24T08:00:00-03:00']],
        ] as [$category, $metadata]) {
            $source = $this->source($category);
            $this->ingest($source, $this->candidate($source, $category, $metadata));
        }

        $this->assertDatabaseCount('incidents', 3);
        $this->assertSame(3, Incident::query()->where('status', IncidentStatus::Reported)->count());
    }

    private function source(string $category): Source
    {
        return Source::query()->create([
            'name' => 'Prensa de servicios '.$category, 'slug' => 'press-schedule-'.$category, 'type' => SourceType::Press,
            'base_url' => 'https://press.test/', 'domain' => 'press.test', 'enabled' => true, 'polling_minutes' => 10,
        ]);
    }

    private function candidate(Source $source, string $category, array $metadata): CandidateDTO
    {
        $title = match ($category) {
            'electricity' => 'Corte de energía programado en alimentador SAN123 de Asunción',
            'water' => 'Corte de agua por mantenimiento de tubería principal en Asunción',
            default => 'Cierre de calle por obras de mantenimiento en Asunción',
        };

        return new CandidateDTO(
            sourceSlug: $source->slug,
            sourceType: SourceType::Press,
            externalIdentifier: 'scheduled-outage',
            title: $title,
            originalUrl: 'https://press.test/'.$category.'/scheduled-outage',
            content: $title,
            publishedAt: CarbonImmutable::parse('2026-10-20 09:00:00 America/Asuncion'),
            detectedLocation: 'Asunción',
            latitude: -25.2867,
            longitude: -57.6333,
            metadata: [...$metadata, 'category_slug' => $category, 'city' => 'Asunción', 'department' => 'Asunción', 'location_text' => 'Asunción', 'location_accuracy' => 'city'],
        );
    }

    private function ingest(Source $source, CandidateDTO $candidate): array
    {
        return app(ScrapedIncidentIngestor::class)->ingest($source, new class($candidate) implements CollectorInterface
        {
            public function __construct(private readonly CandidateDTO $candidate) {}

            public function collect(Source $source): Collection
            {
                return collect([$this->candidate]);
            }
        });
    }
}
