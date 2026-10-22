<?php

namespace Tests\Unit\Collectors;

use App\Collectors\UtilityNewsCollector;
use App\Enums\SourceType;
use App\Models\Source;
use Carbon\CarbonImmutable;
use Database\Seeders\LocationSeeder;
use Database\Seeders\UtilityNewsSourceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class UtilityNewsCollectorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(LocationSeeder::class);
        CarbonImmutable::setTestNow('2026-10-22 15:00:00 America/Asuncion');
        Http::preventStrayRequests();
        config()->set('alertapy.sources.news-test', [
            'api_url' => 'https://news.test/wp-json/wp/v2/posts',
            'search_terms' => ['agua', 'luz'],
            'max_age_hours' => 168, 'page_size' => 25, 'max_pages' => 1,
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_searches_both_services_deduplicates_and_preserves_original_evidence(): void
    {
        Http::fake(['news.test/*' => Http::response([
            $this->newsPost(1),
            $this->newsPost(2, ['title' => ['rendered' => 'Corte de energía eléctrica en Asunción']]),
            $this->newsPost(3, ['title' => ['rendered' => 'ESSAP presenta balance institucional en Asunción']]),
            $this->newsPost(4, ['title' => ['rendered' => 'Incendio en Asunción']]),
            $this->newsPost(5, ['title' => ['rendered' => 'Falta de agua en una localidad'], 'content' => [], 'excerpt' => []]),
        ], 200, ['X-WP-TotalPages' => '1'])]);

        $result = app(UtilityNewsCollector::class)->collect($this->source());

        $this->assertCount(2, $result);
        $this->assertSame(['water', 'electricity'], $result->pluck('metadata.category_slug')->all());
        $this->assertSame('news-test-wp-1', $result[0]->externalIdentifier);
        $this->assertSame('https://news.test/noticia-1/', $result[0]->originalUrl);
        $this->assertSame('2026-10-22T10:00:00-03:00', $result[0]->publishedAt->toIso8601String());
        $this->assertSame('Asunción', $result[0]->detectedLocation);
        $this->assertTrue($result[0]->metadata['utility_news_collector']);
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request['search'] === 'agua' && $request['after'] === '2026-10-15T15:00:00');
        Http::assertSent(fn ($request) => $request['search'] === 'luz' && str_contains($request['_fields'], 'date_gmt'));
    }

    public function test_invalid_missing_old_future_dates_and_foreign_urls_are_rejected(): void
    {
        Http::fake(['news.test/*' => Http::response([
            $this->newsPost(1, ['date_gmt' => '', 'date' => '']),
            $this->newsPost(2, ['date_gmt' => '2026-02-31T10:00:00']),
            $this->newsPost(3, ['date_gmt' => '2026-10-01T10:00:00']),
            $this->newsPost(4, ['date_gmt' => '2026-10-23T10:00:00']),
            $this->newsPost(5, ['date_gmt' => 'today']),
            $this->newsPost(6, ['link' => 'https://news.test.attacker.test/corte/']),
            $this->newsPost(7, ['link' => 'javascript:alert(1)']),
            $this->newsPost(8, ['date_gmt' => '', 'date' => '2026-10-22T11:00:00']),
        ])]);

        $result = app(UtilityNewsCollector::class)->collect($this->source());

        $this->assertCount(1, $result);
        $this->assertSame('news-test-wp-8', $result[0]->externalIdentifier);
        $this->assertSame('2026-10-22T11:00:00-03:00', $result[0]->publishedAt->toIso8601String());
    }

    public function test_pagination_is_bounded_and_later_topic_can_supply_the_newest_result(): void
    {
        config()->set('alertapy.sources.news-test.page_size', 1);
        config()->set('alertapy.sources.news-test.max_pages', 2);
        config()->set('alertapy.sources.news-test.max_candidates', 1);
        Http::fake(function ($request) {
            $id = ($request['search'] === 'agua' ? 1 : 3) + (int) $request['page'];

            return Http::response([$this->newsPost($id, ['date_gmt' => '2026-10-22T1'.$id.':00:00'])], 200, ['X-WP-TotalPages' => '100']);
        });

        $result = app(UtilityNewsCollector::class)->collect($this->source());

        Http::assertSentCount(4);
        $this->assertCount(1, $result);
        $this->assertSame('news-test-wp-5', $result[0]->externalIdentifier);
    }

    public function test_an_empty_successful_response_is_a_valid_zero(): void
    {
        Http::fake(['news.test/*' => Http::response([])]);
        $this->assertCount(0, app(UtilityNewsCollector::class)->collect($this->source()));
        Http::assertSentCount(2);
    }

    public function test_publisher_search_noise_does_not_hide_water_news_on_the_next_page(): void
    {
        // The public API matches "agua" within "Paraguay": the first page can
        // contain one hundred unrelated posts before an operational water alert.
        config()->set('alertapy.sources.news-test', [
            ...config('alertapy.sources.1000noticias'),
            'api_url' => 'https://news.test/wp-json/wp/v2/posts',
            'search_terms' => ['agua', 'luz'],
        ]);
        Http::fake(function ($request) {
            if ($request['search'] === 'luz') {
                return Http::response([], 200, ['X-WP-TotalPages' => '0']);
            }
            $posts = (int) $request['page'] === 1
                ? array_map(fn ($id) => $this->newsPost($id, ['title' => ['rendered' => 'Información institucional de Paraguay']]), range(1, 100))
                : [$this->newsPost(139)];

            return Http::response($posts, 200, ['X-WP-TotalPages' => '2']);
        });

        $result = app(UtilityNewsCollector::class)->collect($this->source());

        $this->assertSame('news-test-wp-139', $result->sole()->externalIdentifier);
        $this->assertSame('water', $result->sole()->metadata['category_slug']);
        Http::assertSentCount(3);
        Http::assertSent(fn ($request) => $request['search'] === 'agua' && $request['page'] === 2 && $request['per_page'] === 100);
    }

    public function test_scheduled_cut_preserves_publication_and_uses_explicit_operating_hours(): void
    {
        Http::fake(['news.test/*' => Http::response([$this->newsPost(1, [
            'date_gmt' => '2026-10-20T18:00:00',
            'title' => ['rendered' => 'Corte de agua potable programado en Asunción'],
            'content' => ['rendered' => '<p>El corte de agua se realizará el sábado 24 de octubre de 08:00 a 11:00.</p>'],
        ])])]);

        $candidate = app(UtilityNewsCollector::class)->collect($this->source())->sole();

        $this->assertSame('2026-10-20T15:00:00-03:00', $candidate->publishedAt->toIso8601String());
        $this->assertSame('2026-10-24T08:00:00-03:00', $candidate->metadata['starts_at']);
        $this->assertSame('2026-10-24T11:00:00-03:00', $candidate->metadata['ends_at']);
    }

    public function test_location_in_article_body_survives_adjacent_html_blocks(): void
    {
        Http::fake(['news.test/*' => Http::response([$this->newsPost(1, [
            'title' => ['rendered' => 'Corte de agua potable afecta a vecinos'],
            'excerpt' => ['rendered' => 'Una tubería sufrió una avería.'],
            'content' => ['rendered' => '<p>El corte afecta a Asunción</p><p>Vecinos reclaman la reposición.</p>'],
        ])])]);

        $result = app(UtilityNewsCollector::class)->collect($this->source());

        $this->assertCount(1, $result);
        $this->assertSame('Asunción', $result[0]->detectedLocation);
    }

    #[DataProvider('invalidResponses')]
    public function test_upstream_errors_do_not_become_healthy_empty_results(string $body, int $status): void
    {
        Http::fake(['news.test/*' => Http::response($body, $status, ['Content-Type' => 'application/json'])]);
        $this->expectException(RuntimeException::class);
        app(UtilityNewsCollector::class)->collect($this->source());
    }

    public static function invalidResponses(): array
    {
        return [['{}', 200], ['{"code":"rest_forbidden"}', 200], ['<html>Challenge</html>', 200], ['[]', 503], ['[null]', 200]];
    }

    public function test_a_failed_second_query_does_not_report_a_partial_run_as_success(): void
    {
        Http::fake(fn ($request) => $request['search'] === 'agua'
            ? Http::response([$this->newsPost(1)])
            : Http::response([], 429));
        $this->expectException(RuntimeException::class);
        app(UtilityNewsCollector::class)->collect($this->source());
    }

    public function test_upgrade_seeder_preserves_existing_source_preferences(): void
    {
        $this->seed(UtilityNewsSourceSeeder::class);
        Source::where('slug', 'nanduti')->update(['enabled' => false, 'polling_minutes' => 60]);
        $this->seed(UtilityNewsSourceSeeder::class);

        $this->assertDatabaseCount('sources', 2);
        $this->assertDatabaseHas('sources', ['slug' => 'nanduti', 'enabled' => false, 'polling_minutes' => 60]);
        $this->assertDatabaseHas('sources', ['slug' => '1000noticias', 'enabled' => true, 'polling_minutes' => 15]);
    }

    private function source(): Source
    {
        return new Source(['name' => 'Prensa', 'slug' => 'news-test', 'type' => SourceType::Press, 'domain' => 'news.test']);
    }

    private function newsPost(int $id, array $changes = []): array
    {
        return array_replace([
            'id' => $id, 'link' => "https://news.test/noticia-{$id}/",
            'date' => '2026-10-22T10:00:00', 'date_gmt' => '2026-10-22T13:00:00',
            'title' => ['rendered' => 'Corte de agua potable en Asunción'],
            'excerpt' => ['rendered' => '<p>Reparación de una tubería.</p>'],
            'content' => ['rendered' => '<p>La avería afecta a vecinos de Asunción.</p>'],
        ], $changes);
    }
}
