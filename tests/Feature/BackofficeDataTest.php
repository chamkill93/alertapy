<?php

namespace Tests\Feature;

use App\Enums\IncidentStatus;
use App\Enums\LocationAccuracy;
use App\Enums\Severity;
use App\Enums\SourceType;
use App\Models\Category;
use App\Models\ContentCandidate;
use App\Models\Incident;
use App\Models\IncidentSource;
use App\Models\RawItem;
use App\Models\SchedulerCycle;
use App\Models\Source;
use App\Models\SourceRun;
use App\Services\BackofficeDataService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BackofficeDataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-22 15:00:00 America/Asuncion'));
        Http::preventStrayRequests();
    }

    public function test_dashboard_reports_real_publications_pending_items_and_scheduler_without_internal_errors(): void
    {
        $source = $this->source('press');
        $disabled = $this->source('disabled', ['enabled' => false]);
        $this->source('failing', ['last_error' => 'https://private.test/?token=cycle-secret', 'consecutive_errors' => 3]);
        $published = $this->raw($source, 'published');
        $this->candidate($published, 'published');
        $this->link($published);
        $this->candidate($this->raw($source, 'pending'), 'waiting_location');
        $this->raw($disabled, 'unprocessed');
        $this->candidate($this->raw($source, 'rejected'), 'rejected_rules');
        $this->raw($source, 'old', ['detected_at' => now()->subDays(2)]);
        SchedulerCycle::query()->create([
            'started_at' => now()->subMinutes(2), 'finished_at' => now()->subMinute(), 'status' => 'completed',
            'sources_processed' => 3, 'candidates_created' => 2, 'incidents_created' => 1,
            'error_summary' => 'token=cycle-secret',
        ]);

        $data = app(BackofficeDataService::class)->dashboard();

        $this->assertSame(4, $data['summary']['received_24h']);
        $this->assertSame(1, $data['summary']['published_24h']);
        $this->assertSame(3, $data['summary']['pending']);
        $this->assertSame(2, $data['summary']['sources_enabled']);
        $this->assertSame(1, $data['summary']['sources_problematic']);
        $this->assertSame('HEALTHY', $data['scheduler']['status']);
        $this->assertSame('failing', $data['attentionConnections'][0]['slug']);
        $this->assertStringNotContainsString('cycle-secret', json_encode($data));
        Http::assertNothingSent();
    }

    public function test_news_filters_use_latest_decision_and_actual_incident_publication_and_preserve_article_dates(): void
    {
        $source = $this->source('water-news');
        $other = $this->source('other');
        $published = $this->raw($source, 'Corte de agua en Asunción', ['published_at' => now()->subHours(3)]);
        $this->candidate($published, 'rejected_rules');
        $incident = $this->link($published);
        $pending = $this->raw($source, 'Corte de energía pendiente');
        $this->candidate($pending, 'pending_ai');
        $rejected = $this->raw($source, 'Nota descartada');
        $this->candidate($rejected, 'pending_ai');
        $this->candidate($rejected, 'rejected_rules');
        $this->candidate($this->raw($other, 'Otra pendiente'), 'pending_ai');
        $this->raw($source, 'Sin procesar');
        $dataService = app(BackofficeDataService::class);

        $publishedData = $dataService->newsIndex(Request::create('/admin/news', 'GET', [
            'source' => 'water-news', 'status' => 'published', 'category' => 'water', 'q' => 'Asunción',
        ]));
        $this->assertSame(1, $publishedData['news']->total());
        $row = $publishedData['news']->items()[0];
        $this->assertSame($published->id, $row['id']);
        $this->assertSame('Publicada', $row['status_label']);
        $this->assertSame($incident->public_id, $row['incidents'][0]['public_id']);
        $this->assertSame($published->published_at->toIso8601String(), $row['published_at']);
        $this->assertSame(2, $dataService->newsIndex(Request::create('/admin/news', 'GET', ['source' => 'water-news', 'status' => 'pending']))['news']->total());
        $this->assertSame($rejected->id, $dataService->newsIndex(Request::create('/admin/news', 'GET', ['status' => 'rejected']))['news']->items()[0]['id']);
        $this->assertSame(1, $dataService->newsIndex(Request::create('/admin/news', 'GET', ['status' => 'unprocessed']))['news']->total());
        $this->assertSame(0, $dataService->newsIndex(Request::create('/admin/news', 'GET', ['q' => 'no-such-story']))['news']->total());
        Http::assertNothingSent();
    }

    public function test_article_detail_is_plain_text_and_omits_tokens_and_unsafe_original_urls(): void
    {
        $source = $this->source('detail');
        $raw = $this->raw($source, '<b>Corte</b> &amp; aviso', [
            'original_url' => 'https://press.test/noticia.php?id=42&token=article-secret&redirect=https://internal.test/',
            'raw_content' => '<p>Texto <b>visible</b></p><script>alert("script-secret")</script><style>.secret{color:red}</style>&lt;img src=x onerror=alert(1)&gt;',
        ]);
        $this->candidate($raw, 'pending_ai', ['ai_reason' => 'Esperar. https://private.test/?token=decision-secret api_key=api-secret']);

        $detail = app(BackofficeDataService::class)->newsDetail($raw);

        $this->assertSame('Corte & aviso', $detail['title']);
        $this->assertSame('https://press.test/noticia.php?id=42', $detail['original_url']);
        $this->assertStringContainsString('Texto visible', $detail['content']);
        $this->assertStringNotContainsString('<', $detail['content']);
        foreach (['script-secret', 'article-secret', 'decision-secret', 'api-secret'] as $secret) {
            $this->assertStringNotContainsString($secret, json_encode($detail));
        }
        $raw->update(['original_url' => 'javascript:alert(1)']);
        $this->assertNull(app(BackofficeDataService::class)->newsDetail($raw->fresh())['original_url']);
        $raw->update(['original_url' => 'https://private:secret@press.test/news']);
        $this->assertNull(app(BackofficeDataService::class)->newsDetail($raw->fresh())['original_url']);
    }

    public function test_news_search_treats_wildcards_as_text_and_malformed_filter_arrays_are_ignored(): void
    {
        $source = $this->source('search');
        $specific = $this->raw($source, 'Informe de red_20%');
        $this->raw($source, 'Informe de red amplia');
        $service = app(BackofficeDataService::class);

        $result = $service->newsIndex(Request::create('/admin/news', 'GET', ['q' => 'red_20%']));
        $this->assertSame(1, $result['news']->total());
        $this->assertSame($specific->id, $result['news']->items()[0]['id']);
        $malformed = $service->newsIndex(Request::create('/admin/news', 'GET', ['q' => ['array'], 'source' => ['array'], 'status' => ['array'], 'category' => ['array']]));
        $this->assertSame(2, $malformed['news']->total());
        $this->assertSame(['q' => '', 'source' => '', 'status' => '', 'category' => ''], $malformed['filters']);
        $connections = $service->connectionsIndex(Request::create('/admin/connections', 'GET', ['q' => ['array'], 'health' => ['array'], 'page' => ['array']]));
        $this->assertSame(1, $connections['connections']->total());
    }

    public function test_connection_catalog_includes_disabled_sources_and_safe_health_and_filters(): void
    {
        $this->source('healthy');
        $disabled = $this->source('disabled', ['enabled' => false, 'configuration_json' => ['inactive_reason' => 'DISABLED_CONFIGURATION: token=private-secret', 'api_key' => 'private-secret']]);
        $this->source('blocked', [
            'last_error' => 'GET https://private.test/?api_key=private-secret blocked',
            'last_error_type' => 'ANTI_BOT_BLOCKED', 'last_http_status' => 403,
        ]);
        $dataService = app(BackofficeDataService::class);

        $data = $dataService->connectionsIndex(Request::create('/admin/connections'));

        $this->assertSame(3, $data['connections']->total());
        $this->assertSame(['total' => 3, 'enabled' => 2, 'healthy' => 1, 'problematic' => 1, 'disabled' => 1], $data['summary']);
        $this->assertStringNotContainsString('private-secret', json_encode($data));
        $this->assertSame(1, $dataService->connectionsIndex(Request::create('/admin/connections', 'GET', ['health' => 'DEGRADED']))['connections']->total());
        $inactive = $dataService->connectionsIndex(Request::create('/admin/connections', 'GET', ['enabled' => '0']))['connections']->items()[0];
        $this->assertSame($disabled->id, $inactive['id']);
        $this->assertNull($inactive['next_run_at']);
        $this->assertSame(1, $dataService->connectionsIndex(Request::create('/admin/connections', 'GET', ['q' => 'blocked']))['connections']->total());
        Http::assertNothingSent();
    }

    public function test_connection_history_is_paginated_read_only_and_never_renders_raw_request_errors(): void
    {
        $source = $this->source('history', ['last_http_status' => 503, 'consecutive_errors' => 2]);
        $raw = $this->raw($source, 'Última noticia');
        $this->link($raw);
        for ($index = 0; $index < 22; $index++) {
            SourceRun::query()->create([
                'source_id' => $source->id, 'started_at' => now()->subMinutes($index), 'finished_at' => now()->subMinutes($index),
                'status' => 'error', 'http_status' => 503, 'error' => 'https://user:private-secret@internal.test/?token=private-secret',
                'discovered' => 3, 'created_candidates' => 1,
            ]);
        }

        $detail = app(BackofficeDataService::class)->connectionDetail($source, Request::create('/admin/connections/'.$source->id));

        $this->assertSame(22, $detail['runs']->total());
        $this->assertCount(20, $detail['runs']->items());
        $this->assertSame(503, $detail['runs']->items()[0]['http_status']);
        $this->assertNotNull($detail['runs']->items()[0]['error_message']);
        $this->assertCount(1, $detail['latestNews']);
        $this->assertCount(1, $detail['latestIncidents']);
        $this->assertStringNotContainsString('private-secret', json_encode($detail));
        $this->assertDatabaseCount('source_runs', 22);
        Http::assertNothingSent();
    }

    public function test_source_catalog_query_count_does_not_grow_per_connection(): void
    {
        $this->source('first');
        $dataService = app(BackofficeDataService::class);
        DB::enableQueryLog();
        $dataService->connectionsIndex(Request::create('/admin/connections'));
        $initialQueries = count(DB::getQueryLog());
        DB::disableQueryLog();
        for ($index = 0; $index < 22; $index++) {
            $this->source('connection-'.$index);
        }
        DB::flushQueryLog();
        DB::enableQueryLog();
        $catalog = $dataService->connectionsIndex(Request::create('/admin/connections'));
        $manyQueries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(23, $catalog['connections']->total());
        $this->assertSame($initialQueries, $manyQueries, 'La salud debe consultar las estructuras en lote, sin una consulta por fuente.');
    }

    public function test_connection_uses_latest_run_counts_and_marks_a_running_query_without_final_counts(): void
    {
        $source = $this->source('run-counts', ['items_seen' => 0, 'items_new' => 0]);
        $run = SourceRun::query()->create([
            'source_id' => $source->id, 'started_at' => now()->subMinute(), 'finished_at' => now(),
            'status' => 'success', 'discovered' => 7, 'created_candidates' => 2,
        ]);
        $service = app(BackofficeDataService::class);
        $connection = $service->connectionDetail($source, Request::create('/admin/connections/'.$source->id))['connection'];
        $this->assertSame(7, $connection['items_seen']);
        $this->assertSame(2, $connection['items_new']);
        $this->assertFalse($connection['last_run_in_progress']);
        $run->update(['status' => 'running', 'finished_at' => null]);
        $connection = $service->connectionDetail($source, Request::create('/admin/connections/'.$source->id))['connection'];
        $this->assertTrue($connection['last_run_in_progress']);
        $this->assertSame('En curso', $connection['run_status_label']);
        $this->assertNull($connection['items_seen']);
        $this->assertNull($connection['items_new']);
    }

    private function source(string $slug, array $attributes = []): Source
    {
        return Source::query()->create([
            'name' => 'Fuente '.$slug, 'slug' => $slug, 'type' => SourceType::Press,
            'base_url' => 'https://press.test/', 'domain' => 'press.test', 'enabled' => true,
            'polling_minutes' => 15, 'last_run_at' => now(), 'last_success_at' => now(),
            ...$attributes,
        ]);
    }

    private function raw(Source $source, string $title, array $attributes = []): RawItem
    {
        return RawItem::query()->create([
            'source_id' => $source->id, 'title' => $title, 'original_url' => 'https://press.test/'.md5($title),
            'raw_content' => $title, 'content_hash' => hash('sha256', $source->id.'-'.$title),
            'published_at' => now()->subHour(), 'detected_at' => now(), ...$attributes,
        ]);
    }

    private function candidate(RawItem $raw, string $status, array $attributes = []): ContentCandidate
    {
        return ContentCandidate::query()->create([
            'source_id' => $raw->source_id, 'raw_item_id' => $raw->id, 'source_type' => SourceType::Press,
            'status' => $status, 'normalized_content' => $raw->title, ...$attributes,
        ]);
    }

    private function link(RawItem $raw): Incident
    {
        $category = Category::query()->firstOrCreate(['slug' => 'water'], [
            'name' => 'Agua', 'icon' => 'water', 'color' => '#00f', 'enabled' => true, 'sort_order' => 1,
        ]);
        $incident = Incident::query()->create([
            'category_id' => $category->id, 'title' => $raw->title, 'summary' => 'Corte de agua', 'source_type' => SourceType::Press,
            'verification_level' => 'press', 'status' => IncidentStatus::Reported, 'severity' => Severity::Medium,
            'location_accuracy' => LocationAccuracy::City, 'location_text' => 'Asunción', 'published_at' => now()->subHour(),
        ]);
        IncidentSource::query()->create([
            'incident_id' => $incident->id, 'source_id' => $raw->source_id, 'raw_item_id' => $raw->id,
            'original_title' => $raw->title, 'original_url' => $raw->original_url, 'detected_at' => now(),
        ]);

        return $incident;
    }
}
