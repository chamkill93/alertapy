<?php

namespace Tests\Feature;

use App\Collectors\DmhAutomaticStationCollector;
use App\Collectors\DmhCapAlertCollector;
use App\Collectors\MadesAirQualityCollector;
use App\Contracts\Collectors\CollectorInterface;
use App\Contracts\Discovery\DiscoveryProviderInterface;
use App\DTOs\CandidateDTO;
use App\DTOs\DiscoveryItemDTO;
use App\Enums\SourceType;
use App\Models\Category;
use App\Models\ContentCandidate;
use App\Models\Incident;
use App\Models\IncidentSource;
use App\Models\IncidentUpdate;
use App\Models\RawItem;
use App\Models\Source;
use App\Services\DataRetentionService;
use App\Services\DiscoveryIngestor;
use App\Services\ScrapedIncidentIngestor;
use Carbon\CarbonImmutable;
use Database\Seeders\CategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DataRetentionTest extends TestCase
{
    use RefreshDatabase;

    private Source $source;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-22 16:00:00 America/Asuncion'));
        Storage::fake('local');
        Http::preventStrayRequests();
        $this->seed(CategorySeeder::class);
        $this->source = Source::query()->create([
            'name' => 'Medio de prueba', 'slug' => 'retention-test', 'type' => SourceType::Press,
            'base_url' => 'https://press.test/', 'domain' => 'press.test', 'enabled' => true, 'polling_minutes' => 10,
        ]);
    }

    public function test_dry_run_preserves_content_and_photos_then_purge_removes_full_expired_graph(): void
    {
        $raw = $this->raw(['published_at' => now()->subDays(8), 'detected_at' => now()]);
        $candidate = ContentCandidate::query()->create(['source_id' => $this->source->id, 'raw_item_id' => $raw->id, 'source_type' => 'press', 'normalized_content' => 'Evidencia']);
        $old = $this->incident(['occurred_at' => now()->subDays(8), 'published_at' => now(), 'last_seen_at' => now(), 'community_photo_path' => 'community-reports/old.jpg']);
        $reference = IncidentSource::query()->create(['incident_id' => $old->id, 'source_id' => $this->source->id, 'raw_item_id' => $raw->id, 'original_title' => 'Original', 'original_url' => 'https://press.test/original', 'detected_at' => now()]);
        $update = IncidentUpdate::query()->create(['incident_id' => $old->id, 'raw_item_id' => $raw->id, 'title' => 'Actualizado', 'summary' => 'Relectura reciente', 'status' => 'reported', 'occurred_at' => now()]);
        DB::table('community_incident_votes')->insert(['incident_id' => $old->id, 'voter_hash' => str_repeat('a', 64), 'vote' => 'positive', 'created_at' => now()]);
        DB::table('location_audits')->insert(['incident_id' => $old->id, 'reason' => 'prueba', 'source' => 'test', 'audited_at' => now()]);
        Storage::disk('local')->put('community-reports/old.jpg', 'old photo');
        $recent = $this->incident();
        $boundary = $this->incident(['occurred_at' => now()->subDays(7)]);

        $preview = app(DataRetentionService::class)->prune(true);
        $this->assertSame(1, $preview['records']['incidents']);
        $this->assertSame(1, $preview['records']['raw_items']);
        $this->assertSame(1, $preview['records']['content_candidates']);
        $this->assertSame(1, $preview['photos']);
        $this->assertDatabaseCount('incidents', 3);
        Storage::disk('local')->assertExists('community-reports/old.jpg');

        $this->artisan('alertapy:prune-data --dry-run')->assertSuccessful();
        $this->assertDatabaseCount('incidents', 3);
        $actual = app(DataRetentionService::class)->prune();
        $this->assertSame($preview['records'], $actual['records']);
        $this->assertModelMissing($old);
        $this->assertModelMissing($raw);
        $this->assertModelMissing($candidate);
        $this->assertModelMissing($reference);
        $this->assertModelMissing($update);
        $this->assertModelExists($recent);
        $this->assertModelExists($boundary);
        $this->assertDatabaseCount('community_incident_votes', 0);
        $this->assertDatabaseCount('location_audits', 0);
        $this->assertModelExists($this->source);
        $this->assertGreaterThan(0, Category::query()->count());
        Storage::disk('local')->assertMissing('community-reports/old.jpg');
        $this->assertSame(0, array_sum(app(DataRetentionService::class)->prune()['records']));
    }

    public function test_a_retained_event_keeps_original_attribution_when_its_old_raw_article_is_removed(): void
    {
        $incident = $this->incident(['community_photo_path' => 'community-reports/shared.jpg']);
        $raw = $this->raw(['published_at' => now()->subDays(8)]);
        $reference = IncidentSource::query()->create(['incident_id' => $incident->id, 'source_id' => $this->source->id, 'raw_item_id' => $raw->id, 'original_title' => 'Aviso original', 'original_url' => 'https://press.test/original', 'published_at' => now()->subDays(8), 'detected_at' => now()->subDays(8)]);
        $oldUpdate = IncidentUpdate::query()->create(['incident_id' => $incident->id, 'title' => 'Antes', 'summary' => 'Viejo', 'status' => 'reported', 'occurred_at' => now()->subDays(8)]);
        $newUpdate = IncidentUpdate::query()->create(['incident_id' => $incident->id, 'title' => 'Ahora', 'summary' => 'Actual', 'status' => 'reported', 'occurred_at' => now()]);
        $this->incident(['occurred_at' => now()->subDays(8), 'community_photo_path' => 'community-reports/shared.jpg']);
        Storage::disk('local')->put('community-reports/shared.jpg', 'shared');

        app(DataRetentionService::class)->prune();

        $this->assertModelMissing($raw);
        $this->assertModelMissing($oldUpdate);
        $this->assertModelExists($newUpdate);
        $this->assertNull($reference->fresh()->raw_item_id);
        $this->assertSame('https://press.test/original', $reference->fresh()->original_url);
        $this->assertModelExists($incident);
        Storage::disk('local')->assertExists('community-reports/shared.jpg');
    }

    public function test_operational_history_is_pruned_but_source_and_station_catalogs_remain(): void
    {
        $station = DB::table('monitoring_stations')->insertGetId(['source_id' => $this->source->id, 'external_id' => 'station', 'name' => 'Estación', 'kind' => 'weather', 'latitude' => -25.3, 'longitude' => -57.6]);
        foreach ([now()->subDays(8), now()->subDay()] as $date) {
            DB::table('source_runs')->insert(['source_id' => $this->source->id, 'started_at' => $date, 'status' => 'success']);
            DB::table('scheduler_cycles')->insert(['started_at' => $date, 'status' => 'success']);
            DB::table('source_parser_snapshots')->insert(['source_id' => $this->source->id, 'structure_signature' => str_repeat('a', 64), 'page_hash' => str_repeat('b', 64), 'parser_version' => 'test', 'observed_at' => $date]);
            DB::table('station_measurements')->insert(['monitoring_station_id' => $station, 'parameter' => 'temperature', 'value' => 20, 'measured_at' => $date]);
            DB::table('weather_alerts')->insert(['external_id' => $date->toIso8601String(), 'event' => 'Tormenta', 'headline' => 'Alerta', 'sent_at' => $date, 'source_url' => 'https://press.test/', 'synced_at' => now()]);
        }

        app(DataRetentionService::class)->prune();

        foreach (['source_runs', 'scheduler_cycles', 'source_parser_snapshots', 'station_measurements', 'weather_alerts'] as $table) {
            $this->assertDatabaseCount($table, 1);
        }
        $this->assertDatabaseCount('monitoring_stations', 1);
        $this->assertModelExists($this->source);
    }

    public function test_photo_cleanup_is_confined_to_expired_community_files_and_preserves_recent_or_shared_files(): void
    {
        $disk = Storage::disk('local');
        $disk->put('community-reports/orphan.jpg', 'old orphan');
        touch($disk->path('community-reports/orphan.jpg'), now()->subDays(8)->getTimestamp());
        $disk->put('community-reports/recent.jpg', 'recent upload');
        $disk->put('protected.txt', 'must stay');
        $this->incident(['occurred_at' => now()->subDays(8), 'community_photo_path' => 'protected.txt']);

        $result = app(DataRetentionService::class)->prune();

        $this->assertSame(1, $result['photos']);
        $disk->assertMissing('community-reports/orphan.jpg');
        $disk->assertExists('community-reports/recent.jpg');
        $disk->assertExists('protected.txt');
    }

    public function test_monitoring_feeds_do_not_restore_expired_or_future_observations_and_alerts(): void
    {
        config()->set('alertapy.sources.dmh-automatic-stations.feed_url', 'https://weather.test/stations');
        config()->set('alertapy.sources.dmh-cap.messages_url', 'https://weather.test/alerts');
        config()->set('alertapy.sources.mades-air-quality.api_url', 'https://air.test/api');
        config()->set('alertapy.sources.mades-air-quality.api_key', 'fixture-key');
        $old = now()->subDays(8)->toIso8601String();
        $future = now()->addHour()->toIso8601String();
        $stations = [];
        $alerts = [];
        foreach ([$old, $future] as $index => $date) {
            $stations['station-'.$index] = ['metadatos' => ['nombre' => 'Estación', 'estado' => 'activo', 'latitud' => -25.3, 'longitud' => -57.6], 'tiene_datos' => 'si', 'ultima_observacion' => ['fecha' => ['utc' => $date], 'temperatura_aire' => ['valor' => 25]]];
            $xml = '<alert><identifier>cap-'.$index.'</identifier><sent>'.$date.'</sent><info><event>Tormenta</event><expires>'.now()->addDay()->toIso8601String().'</expires></info></alert>';
            $alerts[] = ['properties' => ['content' => ['value' => base64_encode($xml)]]];
        }
        Http::fake([
            'weather.test/stations*' => Http::response(['estaciones' => $stations]),
            'weather.test/alerts*' => Http::response(['features' => $alerts]),
            'air.test/api/stations' => Http::response(['data' => [['id' => 7, 'name' => 'Aire', 'latitude' => -25.3, 'longitude' => -57.6, 'is_active' => true, 'is_collecting_data' => true]]]),
            'air.test/api/stations/7/data*' => Http::response(['data' => array_map(fn (string $date) => ['parameter_name' => 'PM2.5', 'parameter_value' => 12, 'date_time' => $date], [$old, $future, now()->subHour()->toIso8601String()])]),
        ]);

        $this->assertSame(0, app(DmhAutomaticStationCollector::class)->sync($this->source)['measurements']);
        $this->assertSame(0, app(DmhCapAlertCollector::class)->sync()['stored']);
        $this->assertSame(1, app(MadesAirQualityCollector::class)->sync($this->source)['measurements']);
        $this->assertDatabaseCount('station_measurements', 1);
        $this->assertDatabaseCount('weather_alerts', 0);
        $this->assertDatabaseCount('monitoring_stations', 3);
    }

    public function test_retention_uses_real_event_and_article_dates_with_nullable_fallbacks(): void
    {
        $byStart = $this->incident(['occurred_at' => null, 'starts_at' => now()->subDays(8), 'published_at' => now()]);
        $byPublication = $this->incident(['occurred_at' => null, 'starts_at' => null, 'published_at' => now()->subDays(8)]);
        $byCreation = $this->incident(['occurred_at' => null, 'starts_at' => null, 'published_at' => null, 'detected_at' => null]);
        $byCreation->forceFill(['created_at' => now()->subDays(8), 'updated_at' => now()])->save();
        $undated = $this->raw(['published_at' => null, 'detected_at' => now()->subDays(8)]);
        $newEvent = $this->incident(['occurred_at' => now(), 'starts_at' => now()->subDays(8)]);

        app(DataRetentionService::class)->prune();

        foreach ([$byStart, $byPublication, $byCreation, $undated] as $model) $this->assertModelMissing($model);
        $this->assertModelExists($newEvent);
    }

    public function test_a_future_event_does_not_extend_an_old_publication_or_its_photo_retention(): void
    {
        $oldAnnouncement = $this->incident([
            'occurred_at' => null, 'starts_at' => now()->addDays(2), 'published_at' => now()->subDays(8),
            'detected_at' => now(), 'last_seen_at' => now(), 'community_photo_path' => 'community-reports/old-announcement.jpg',
        ]);
        $oldDetection = $this->incident([
            'occurred_at' => null, 'starts_at' => now()->addDay(), 'published_at' => null,
            'detected_at' => now()->subDays(8), 'community_photo_path' => 'community-reports/old-detection.jpg',
        ]);
        $boundary = $this->incident([
            'occurred_at' => null, 'starts_at' => now()->addDay(), 'published_at' => now()->subDays(7),
            'community_photo_path' => 'community-reports/boundary.jpg',
        ]);
        foreach (['old-announcement', 'old-detection', 'boundary'] as $photo) {
            Storage::disk('local')->put('community-reports/'.$photo.'.jpg', 'photo');
        }

        $preview = app(DataRetentionService::class)->prune(true);
        $this->assertSame(2, $preview['records']['incidents']);
        $this->assertSame(2, $preview['photos']);
        $this->assertDatabaseCount('incidents', 3);
        Storage::disk('local')->assertExists('community-reports/old-announcement.jpg');

        app(DataRetentionService::class)->prune();

        $this->assertModelMissing($oldAnnouncement);
        $this->assertModelMissing($oldDetection);
        $this->assertModelExists($boundary);
        Storage::disk('local')->assertMissing('community-reports/old-announcement.jpg');
        Storage::disk('local')->assertMissing('community-reports/old-detection.jpg');
        Storage::disk('local')->assertExists('community-reports/boundary.jpg');
    }

    public function test_expired_news_cannot_return_via_scraping_or_discovery_and_future_schedules_still_work(): void
    {
        config()->set('alertapy.ingestion.local_rules_publish', true);
        foreach ([now()->subDays(8)->toImmutable(), null, now()->addHour()->toImmutable()] as $publishedAt) {
            $candidate = $this->scrapedCandidate($publishedAt);
            $stats = app(ScrapedIncidentIngestor::class)->ingest($this->source, $this->collector($candidate));
            $this->assertSame(1, $stats['ignored_date']);
            $item = new DiscoveryItemDTO('discovery', $candidate->title, $candidate->originalUrl, $candidate->content, $publishedAt, SourceType::Discovery);
            $provider = new class($item) implements DiscoveryProviderInterface {
                public function __construct(private DiscoveryItemDTO $item) {}
                public function discover(Source $source): Collection { return collect([$this->item]); }
            };
            $this->assertSame(1, app(DiscoveryIngestor::class)->ingest($this->source, $provider)['ignored_date']);
        }
        $this->assertDatabaseCount('raw_items', 0);
        $this->assertDatabaseCount('incidents', 0);
        $this->assertDatabaseCount('content_candidates', 0);

        $candidate = $this->scrapedCandidate(now()->subHour()->toImmutable(), ['starts_at' => now()->addDay()->toIso8601String(), 'ends_at' => now()->addDay()->addHours(2)->toIso8601String()]);
        $this->assertSame(1, app(ScrapedIncidentIngestor::class)->ingest($this->source, $this->collector($candidate))['published']);
        $this->assertSame('scheduled', Incident::query()->sole()->status->value);
    }

    public function test_scraped_event_expiration_is_based_on_actual_occurrence_and_cycle_applies_retention(): void
    {
        config()->set('alertapy.ingestion.local_rules_publish', true);
        $occurredAt = now()->subHours(30)->toImmutable();
        $candidate = $this->scrapedCandidate(now()->subHour()->toImmutable(), ['category_slug' => 'fire', 'occurred_at' => $occurredAt->toIso8601String()]);
        app(ScrapedIncidentIngestor::class)->ingest($this->source, $this->collector($candidate));
        $incident = Incident::query()->sole();
        $this->assertTrue($incident->starts_at->equalTo($occurredAt));
        $this->assertTrue($incident->expires_at->equalTo($occurredAt->addHours(18)));
        $old = $this->incident(['occurred_at' => now()->subDays(8)]);
        $this->source->forceFill(['enabled' => false])->save();
        $this->artisan('alertapy:cycle')->assertSuccessful();
        $this->assertModelMissing($old);
        $this->assertModelExists($incident);
    }

    private function incident(array $overrides = []): Incident
    {
        return Incident::query()->create([
            'category_id' => Category::query()->where('slug', 'fire')->value('id'),
            'title' => 'Incendio', 'summary' => 'Incendio en Asunción', 'source_type' => 'press', 'verification_level' => 'press',
            'status' => 'reported', 'severity' => 'medium', 'latitude' => -25.3, 'longitude' => -57.6,
            'location_accuracy' => 'city', 'location_text' => 'Asunción', 'occurred_at' => now()->subHour(),
            'starts_at' => now()->subHour(), 'published_at' => now()->subHour(), ...$overrides,
        ]);
    }

    private function raw(array $overrides = []): RawItem
    {
        $identifier = bin2hex(random_bytes(4));
        return RawItem::query()->create(['source_id' => $this->source->id, 'external_identifier' => $identifier, 'original_url' => 'https://press.test/'.$identifier, 'title' => 'Noticia', 'raw_content' => 'Contenido', 'content_hash' => hash('sha256', $identifier), 'published_at' => now()->subHour(), 'detected_at' => now(), ...$overrides]);
    }

    private function scrapedCandidate(?CarbonImmutable $publishedAt, array $metadata = []): CandidateDTO
    {
        $category = $metadata['category_slug'] ?? 'water';
        $title = $category === 'fire' ? 'Incendio afecta depósito en Asunción' : 'Corte de agua por mantenimiento de tubería principal en Asunción';
        return new CandidateDTO($this->source->slug, SourceType::Press, 'story', $title, 'https://press.test/story', $title, $publishedAt, 'Asunción', -25.2867, -57.6333, ['category_slug' => $category, 'city' => 'Asunción', 'department' => 'Asunción', 'location_text' => 'Asunción', 'location_accuracy' => 'city', ...$metadata]);
    }

    private function collector(CandidateDTO $candidate): CollectorInterface
    {
        return new class($candidate) implements CollectorInterface {
            public function __construct(private CandidateDTO $candidate) {}
            public function collect(Source $source): Collection { return collect([$this->candidate]); }
        };
    }
}
