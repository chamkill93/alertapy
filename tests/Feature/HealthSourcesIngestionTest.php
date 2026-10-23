<?php

namespace Tests\Feature;

use App\Collectors\HealthNewsCollector;
use App\Collectors\MspbsCollector;
use App\Enums\LocationAccuracy;
use App\Models\GeocodingCache;
use App\Models\Source;
use Carbon\CarbonImmutable;
use Database\Seeders\CategorySeeder;
use Database\Seeders\LocationSeeder;
use Database\Seeders\SourceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HealthSourcesIngestionTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_mspbs_health_response_to_floods_is_collected_for_health_and_flood_layers(): void
    {
        CarbonImmutable::setTestNow('2026-10-23 12:00:00 America/Asuncion');
        $this->seed([CategorySeeder::class, SourceSeeder::class, LocationSeeder::class]);
        $source = Source::query()->where('slug', 'mspbs-health')->sole();
        $title = 'Equipos de salud asisten a comunidades afectadas por inundaciones en Misiones';
        $articleUrl = 'https://www.mspbs.gov.py/portal/36762/equipos-de-salud-asisten-a-comunidades-afectadas-por-inundaciones-en-misiones.html';

        Http::preventStrayRequests();
        Http::fake(function ($request) use ($title, $articleUrl) {
            if (str_contains($request->url(), 'mspbs.gov.py') && $request->url() !== $articleUrl) {
                return Http::response('<main><h2><a href="'.$articleUrl.'">'.$title.'</a></h2></main>');
            }

            if ($request->url() === $articleUrl) {
                return Http::response('<html><head>
                    <meta property="og:title" content="'.$title.'">
                    <meta property="og:description" content="Equipos del Ministerio de Salud brindan atención a comunidades afectadas por inundaciones en Misiones.">
                    <meta property="article:published_time" content="2026-10-09T10:00:00-03:00">
                    </head><body><main>'.$title.'</main></body></html>');
            }

            return Http::response('Unexpected URL', 404);
        });

        $candidates = app(HealthNewsCollector::class)->collect($source);
        $categories = $candidates->pluck('metadata.category_slug')->all();
        $healthCandidate = $candidates->firstWhere('metadata.category_slug', 'health_public');

        $this->assertEqualsCanonicalizing(['health_public', 'weather_flood'], $categories);
        $this->assertCount(2, $candidates->pluck('externalIdentifier')->unique());
        $this->assertNotNull($healthCandidate);
        $this->assertTrue($healthCandidate->metadata['category_variant']);
        $this->assertSame($articleUrl, $healthCandidate->originalUrl);
    }

    public function test_mspbs_featured_listing_collects_recent_health_news_with_its_original_reference(): void
    {
        CarbonImmutable::setTestNow('2026-10-23 12:00:00 America/Asuncion');
        $this->seed([CategorySeeder::class, SourceSeeder::class, LocationSeeder::class]);
        $source = Source::query()->where('slug', 'mspbs-health-featured')->sole();
        $title = 'Más de 27 mil atenciones especializadas registra el Hospital General San Lorenzo';
        $articleUrl = 'https://www.mspbs.gov.py/hospital-general-san-lorenzo-atenciones';
        Http::preventStrayRequests();
        Http::fake(function ($request) use ($title, $articleUrl) {
            if (str_contains($request->url(), '/buscar.php?')) {
                return Http::response('<main><h3><a href="'.$articleUrl.'">'.$title.'</a></h3></main>');
            }

            if ($request->url() === $articleUrl) {
                return Http::response('<html><head>
                    <meta property="og:title" content="'.$title.'">
                    <meta property="og:description" content="El Hospital General San Lorenzo registró atenciones especializadas en San Lorenzo, departamento Central.">
                    <meta property="article:published_time" content="2026-10-19T12:00:00-03:00">
                    </head><body><main>'.$title.'</main></body></html>');
            }

            return Http::response('Unexpected URL', 404);
        });

        $candidates = app(HealthNewsCollector::class)->collect($source);

        $this->assertCount(1, $candidates);
        $this->assertSame($title, $candidates[0]->title);
        $this->assertSame($articleUrl, $candidates[0]->originalUrl);
        $this->assertSame('health_public', $candidates[0]->metadata['category_slug']);
        $this->assertSame('San Lorenzo, Central', $candidates[0]->detectedLocation);
        $this->assertSame('2026-10-19T12:00:00-03:00', $candidates[0]->publishedAt?->toIso8601String());
    }

    public function test_mspbs_dengue_notice_uses_article_lead_for_public_health_correlation(): void
    {
        CarbonImmutable::setTestNow('2026-10-13 12:00:00 America/Asuncion');
        $this->seed([CategorySeeder::class, SourceSeeder::class, LocationSeeder::class]);
        $source = Source::query()->where('slug', 'mspbs-health')->sole();
        $title = 'Dengue: tras lluvias se insta a reforzar la prevención de criaderos';
        $articleUrl = 'https://www.mspbs.gov.py/portal/36653/dengue-tras-lluvias-se-insta-a-reforzar-la-prevencion-de-criaderos.html';

        Http::preventStrayRequests();
        Http::fake(function ($request) use ($title, $articleUrl) {
            if ($request->url() === $articleUrl) {
                return Http::response('<html><head>
                    <meta property="og:title" content="'.$title.'">
                    <meta property="og:description" content="En Misiones, el reporte de vigilancia epidemiológica actualiza la situación sanitaria del dengue, con notificaciones de casos sospechosos y sin brotes confirmados.">
                    <meta property="article:published_time" content="2026-10-23T10:00:00-03:00">
                    </head><body><main>'.$title.'</main></body></html>');
            }

            if (str_contains($request->url(), 'mspbs.gov.py')) {
                return Http::response('<main><h2><a href="'.$articleUrl.'">'.$title.'</a></h2></main>');
            }

            return Http::response('Unexpected URL', 404);
        });

        $candidates = app(MspbsCollector::class)->collect($source);

        $this->assertSame(['health_vector', 'health_public'], $candidates->pluck('metadata.category_slug')->all());
        $this->assertSame('Misiones', $candidates->first()->metadata['department']);
        $this->assertSame($articleUrl, $candidates->first()->originalUrl);
    }

    public function test_erssan_multi_department_updates_become_one_incident_per_department(): void
    {
        CarbonImmutable::setTestNow('2026-08-19 23:00:00 America/Asuncion');
        Cache::clear();
        $this->seed([CategorySeeder::class, SourceSeeder::class, LocationSeeder::class]);
        GeocodingCache::query()->insert([
            ['query' => 'Departamento de Canindeyú', 'normalized_query' => 'departamento de canindeyu', 'latitude' => -24.25, 'longitude' => -55.25, 'precision' => LocationAccuracy::Department->value, 'provider' => 'test', 'hit_count' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['query' => 'Departamento de Concepción', 'normalized_query' => 'departamento de concepcion', 'latitude' => -23.40, 'longitude' => -57.43, 'precision' => LocationAccuracy::Department->value, 'provider' => 'test', 'hit_count' => 0, 'created_at' => now(), 'updated_at' => now()],
        ]);
        // At Aug 19, 23:00, a date-only Aug 12 article is already 7 days
        // and 23 hours old. Keep it as a negative case; Aug 13–15 provide
        // three valid updates for each of the two departments.
        $cards = collect([12, 13, 14, 15])->map(fn (int $day): string => "<article><h2><a href=\"https://erssan.gov.py/control-{$day}/\">Supervisión de calidad del agua en Canindeyú y Concepción</a></h2><time>Publicado el {$day} de agosto de 2026</time></article>")->implode('');
        Http::fake([
            'erssan.gov.py/noticias/' => Http::response("<main>{$cards}</main>"),
            'erssan.gov.py/control-*' => Http::response('<main><p>ERSSAN realiza la supervisión oficial en los departamentos de Canindeyú y Concepción.</p></main>'),
        ]);

        $this->artisan('alertapy:scrape erssan --force --days=7')
            ->expectsOutputToContain('2 omitidos por fecha')
            ->assertSuccessful();

        $this->assertDatabaseCount('incidents', 2);
        $this->assertDatabaseCount('incident_sources', 6);
        $this->assertDatabaseCount('incident_updates', 6);
        $this->assertDatabaseMissing('raw_items', ['original_url' => 'https://erssan.gov.py/control-12/']);
        $this->assertSame(2, \App\Models\IncidentUpdate::query()->where('event_type', 'detected')->count());
        $this->assertSame(4, \App\Models\IncidentUpdate::query()->where('event_type', 'updated')->count());
        $this->assertDatabaseHas('incidents', ['department' => 'Canindeyú', 'city' => null, 'location_accuracy' => 'department']);
        $this->assertDatabaseHas('incidents', ['department' => 'Concepción', 'city' => null, 'location_accuracy' => 'department']);
    }
}
