<?php

namespace Tests\Unit\Collectors;

use App\Collectors\EssapCollector;
use App\Enums\SourceType;
use App\Models\Source;
use Carbon\CarbonImmutable;
use Database\Seeders\LocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class EssapCollectorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(LocationSeeder::class);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_it_keeps_only_official_geolocatable_water_events(): void
    {
        CarbonImmutable::setTestNow('2026-08-18 16:00:00 America/Asuncion');
        config()->set('alertapy.sources.essap.lookback_days', 7);
        $source = Source::query()->create([
            'name' => 'ESSAP',
            'slug' => 'essap',
            'type' => SourceType::Official,
            'base_url' => 'https://www.essap.com.py/',
            'domain' => 'essap.com.py',
            'enabled' => true,
            'polling_minutes' => 10,
        ]);
        Http::fake([
            'www.essap.com.py/wp-json/wp/v2/posts*' => Http::response([
                [
                    'id' => 70001,
                    'date' => '2026-08-17T09:30:00',
                    'link' => 'https://www.essap.com.py/rotura-tuberia-asuncion/',
                    'title' => ['rendered' => 'Rotura de tubería principal ocasiona falta de agua en Asunción'],
                    'excerpt' => ['rendered' => '<p>Cuadrillas de ESSAP realizan la reparación.</p>'],
                    'content' => ['rendered' => '<p>El servicio se restablecerá progresivamente.</p>'],
                    'categories' => [6],
                ],
                [
                    'id' => 70002,
                    'date' => '2026-08-16T10:00:00',
                    'link' => 'https://www.essap.com.py/capacitacion-funcionarios/',
                    'title' => ['rendered' => 'ESSAP capacita a funcionarios en Asunción'],
                    'excerpt' => ['rendered' => '<p>Una actividad institucional.</p>'],
                    'content' => ['rendered' => '<p>La capacitación se desarrolló con normalidad.</p>'],
                    'categories' => [6],
                ],
            ], 200, ['X-WP-TotalPages' => '1']),
        ]);

        $candidates = app(EssapCollector::class)->collect($source);

        $this->assertCount(1, $candidates);
        $this->assertSame('essap-70001', $candidates[0]->externalIdentifier);
        $this->assertSame('water', $candidates[0]->metadata['category_slug']);
        $this->assertSame('Asunción', $candidates[0]->detectedLocation);
        $this->assertTrue($candidates[0]->metadata['official_api']);
        Http::assertSent(fn ($request): bool => $request['categories'] === '6,7,34,35');
        Http::assertSent(fn ($request): bool => $request['after'] === '2026-08-11T16:00:00'
            && str_contains($request['_fields'], 'date_gmt'));
    }

    public function test_missing_and_future_publication_dates_are_not_treated_as_recent_incidents(): void
    {
        CarbonImmutable::setTestNow('2026-10-22 15:00:00 America/Asuncion');
        $post = [
            'id' => 1, 'link' => 'https://www.essap.com.py/corte-asuncion/',
            'title' => ['rendered' => 'Corte de agua potable en Asunción'],
            'excerpt' => ['rendered' => 'Reparación de tubería principal.'],
            'content' => ['rendered' => 'Trabajos de reparación.'],
        ];
        Http::fake(['www.essap.com.py/wp-json/wp/v2/posts*' => Http::response([
            [...$post, 'date' => ''],
            [...$post, 'id' => 2, 'date' => '2026-10-23T10:00:00'],
            [...$post, 'id' => 3, 'date' => '2026-10-22T10:00:00'],
        ])]);

        $candidates = app(EssapCollector::class)->collect(new Source(['slug' => 'essap', 'type' => SourceType::Official]));

        $this->assertCount(1, $candidates);
        $this->assertSame('essap-3', $candidates->first()->externalIdentifier);
    }

    public function test_unlocated_water_notice_uses_asuncion_as_an_explicit_map_reference(): void
    {
        CarbonImmutable::setTestNow('2026-10-22 15:00:00 America/Asuncion');
        Http::fake(['www.essap.com.py/wp-json/wp/v2/posts*' => Http::response([[
            'id' => 70100,
            'date' => '2026-10-22T10:00:00',
            'link' => 'https://www.essap.com.py/reparacion-tuberia-principal/',
            'title' => ['rendered' => 'Corte de agua potable por reparación de tubería principal'],
            'excerpt' => ['rendered' => 'El servicio se restablecerá al concluir los trabajos.'],
            'content' => ['rendered' => '<p>Cuadrillas realizan la reparación.</p>'],
            'categories' => [6],
        ]])]);

        $candidate = app(EssapCollector::class)->collect(new Source(['slug' => 'essap', 'type' => SourceType::Official]))->sole();

        $this->assertSame('Asunción', $candidate->detectedLocation);
        $this->assertSame(-25.2800459, $candidate->latitude);
        $this->assertSame(-57.6343814, $candidate->longitude);
        $this->assertTrue($candidate->metadata['location_is_fallback']);
        $this->assertNull($candidate->metadata['city']);
        $this->assertNull($candidate->metadata['department']);
    }

    public function test_gmt_date_preserves_recent_boundary_and_rejects_invalid_dates_or_reference_links(): void
    {
        CarbonImmutable::setTestNow('2026-10-22 15:00:00 America/Asuncion');
        config()->set('alertapy.sources.essap.max_age_hours', 168);
        $post = [
            'id' => 1, 'link' => 'https://www.essap.com.py/corte-asuncion/',
            'date' => '2026-10-15T15:01:00', 'date_gmt' => '2026-10-15T18:01:00',
            'title' => ['rendered' => 'Corte de agua potable afecta a vecinos'],
            'excerpt' => ['rendered' => 'Reparación de tubería principal.'],
            'content' => ['rendered' => '<p>El corte afecta a Asunción</p><p>Vecinos reclaman la reposición.</p>'],
        ];
        Http::fake(['www.essap.com.py/wp-json/wp/v2/posts*' => Http::response([
            $post,
            [...$post, 'id' => 2, 'date_gmt' => '2026-02-31T10:00:00'],
            [...$post, 'id' => 3, 'date_gmt' => 'today'],
            [...$post, 'id' => 4, 'link' => 'https://essap.com.py.attacker.test/noticia/'],
            [...$post, 'id' => 5, 'link' => 'javascript:alert(1)'],
            [...$post, 'id' => 6, 'date_gmt' => '2026-10-15T17:59:59'],
        ])]);

        $result = app(EssapCollector::class)->collect(new Source(['slug' => 'essap', 'type' => SourceType::Official]));

        $this->assertCount(1, $result);
        $this->assertSame('2026-10-15T15:01:00-03:00', $result->sole()->publishedAt->toIso8601String());
        $this->assertSame('Asunción', $result->sole()->detectedLocation);
        $this->assertSame('https://www.essap.com.py/corte-asuncion/', $result->sole()->originalUrl);
        Http::assertSent(fn ($request): bool => $request['after'] === '2026-10-15T15:00:00');
    }

    #[DataProvider('malformedResponses')]
    public function test_invalid_api_payloads_are_errors_instead_of_healthy_empty_results(string $body): void
    {
        Http::fake(['www.essap.com.py/wp-json/wp/v2/posts*' => Http::response($body)]);
        $this->expectException(RuntimeException::class);
        app(EssapCollector::class)->collect(new Source(['slug' => 'essap', 'type' => SourceType::Official]));
    }

    public static function malformedResponses(): array
    {
        return [['{}'], ['{"code":"rest_forbidden"}'], ['<html>Challenge</html>'], ['[null]']];
    }

    public function test_empty_valid_api_list_remains_a_successful_zero(): void
    {
        Http::fake(['www.essap.com.py/wp-json/wp/v2/posts*' => Http::response([])]);
        $this->assertCount(0, app(EssapCollector::class)->collect(new Source(['slug' => 'essap', 'type' => SourceType::Official])));
    }
}

