<?php

namespace Tests\Feature;

use App\Models\ContentCandidate;
use App\Models\Incident;
use App\Models\Location;
use App\Models\Source;
use Carbon\CarbonImmutable;
use Database\Seeders\CategorySeeder;
use Database\Seeders\LocationSeeder;
use Database\Seeders\SourceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ScrapeSourcesCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_it_persists_real_source_evidence_and_exposes_markers_idempotently(): void
    {
        CarbonImmutable::setTestNow('2026-08-18 16:00:00 America/Asuncion');
        Cache::clear();
        config()->set('alertapy.ingestion.local_rules_publish', true);
        $this->seed([CategorySeeder::class, SourceSeeder::class, LocationSeeder::class]);
        Location::query()->insert([
            [
                'type' => 'neighborhood',
                'name' => 'Pedrozo, Ypacaraí',
                'normalized_name' => 'pedrozo, ypacarai',
                'latitude' => -25.3860,
                'longitude' => -57.2680,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'type' => 'department',
                'name' => 'Departamento de Alto Paraná',
                'normalized_name' => 'departamento de alto parana',
                'latitude' => -25.5000,
                'longitude' => -54.8000,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
        Http::fake([
            'www.abc.com.py/arc/outboundfeeds/rss/nacionales/' => Http::response(
                file_get_contents(base_path('tests/Fixtures/abc-nacionales.xml')),
                200,
                ['Content-Type' => 'application/xml'],
            ),
        ]);

        $this->artisan('alertapy:scrape abc --force')->assertSuccessful();

        $this->assertDatabaseCount('raw_items', 2);
        $this->assertDatabaseCount('content_candidates', 2);
        $this->assertDatabaseCount('incidents', 2);
        $this->assertDatabaseCount('incident_sources', 2);
        $this->getJson('/api/incidents?period=24h')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.source.label', 'Prensa');

        Source::query()->where('slug', 'abc')->update(['last_run_at' => null]);
        $this->artisan('alertapy:scrape abc --force')->assertSuccessful();
        $this->assertDatabaseCount('incidents', 2);
        $this->assertDatabaseCount('incident_sources', 2);
    }

    public function test_ande_anti_bot_page_is_recorded_as_an_error_without_publishing(): void
    {
        $this->seed([CategorySeeder::class, SourceSeeder::class, LocationSeeder::class]);
        Http::fake([
            'www.ande.gov.py/*' => Http::response('<html><title>Radware Page</title><p>Verifying your browser before proceeding...</p></html>'),
        ]);

        $this->artisan('alertapy:scrape ande --force')->assertFailed();

        $this->assertDatabaseCount('incidents', 0);
        $this->assertDatabaseHas('source_runs', ['status' => 'error']);
        $this->assertStringContainsString(
            'no se intentó evadirla',
            (string) Source::query()->where('slug', 'ande')->value('last_error'),
        );
    }

    public function test_production_safe_mode_keeps_scraped_candidates_pending_without_publishing(): void
    {
        CarbonImmutable::setTestNow('2026-08-18 16:00:00 America/Asuncion');
        Cache::clear();
        config()->set('alertapy.ingestion.local_rules_publish', false);
        $this->seed([CategorySeeder::class, SourceSeeder::class, LocationSeeder::class]);
        Location::query()->insert([
            [
                'type' => 'neighborhood',
                'name' => 'Pedrozo, Ypacaraí',
                'normalized_name' => 'pedrozo, ypacarai',
                'latitude' => -25.3860,
                'longitude' => -57.2680,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'type' => 'department',
                'name' => 'Departamento de Alto Paraná',
                'normalized_name' => 'departamento de alto parana',
                'latitude' => -25.5000,
                'longitude' => -54.8000,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
        Http::fake([
            'www.abc.com.py/arc/outboundfeeds/rss/nacionales/' => Http::response(
                file_get_contents(base_path('tests/Fixtures/abc-nacionales.xml')),
                200,
                ['Content-Type' => 'application/xml'],
            ),
        ]);

        $this->artisan('alertapy:scrape abc --force')->assertSuccessful();

        $this->assertDatabaseCount('incidents', 0);
        $this->assertDatabaseCount('content_candidates', 2);
        $this->assertDatabaseCount('raw_items', 2);
        $this->assertSame(2, ContentCandidate::query()->where('status', 'pending_ai')->count());
    }

    public function test_official_essap_events_publish_without_ai_and_are_idempotent(): void
    {
        CarbonImmutable::setTestNow('2026-08-18 16:00:00 America/Asuncion');
        Cache::clear();
        config()->set('alertapy.ingestion.local_rules_publish', false);
        config()->set('alertapy.ingestion.official_sources_publish', true);
        $this->seed([CategorySeeder::class, SourceSeeder::class, LocationSeeder::class]);
        Location::query()->create([
            'type' => 'city',
            'name' => 'Asunción',
            'normalized_name' => 'asuncion',
            'latitude' => -25.2867,
            'longitude' => -57.3333,
        ]);
        Http::fake([
            'www.essap.com.py/wp-json/wp/v2/posts*' => Http::response([[
                'id' => 70001,
                'date' => '2026-08-17T09:30:00',
                'link' => 'https://www.essap.com.py/rotura-tuberia-asuncion/',
                'title' => ['rendered' => 'Rotura de tubería principal ocasiona falta de agua en Asunción'],
                'excerpt' => ['rendered' => '<p>Cuadrillas de ESSAP realizan la reparación.</p>'],
                'content' => ['rendered' => '<p>El servicio se restablecerá progresivamente.</p>'],
                'categories' => [6],
            ]], 200, ['X-WP-TotalPages' => '1']),
        ]);

        $this->artisan('alertapy:scrape essap --force --days=7')->assertSuccessful();

        $this->assertDatabaseCount('raw_items', 1);
        $this->assertDatabaseCount('incidents', 1);
        $this->assertDatabaseHas('content_candidates', ['status' => 'published']);
        $this->assertDatabaseHas('incidents', ['source_type' => 'official', 'city' => 'Asunción']);

        Source::query()->where('slug', 'essap')->update(['last_run_at' => null]);
        $this->artisan('alertapy:scrape essap --force --days=7')->assertSuccessful();
        $this->assertDatabaseCount('raw_items', 1);
        $this->assertDatabaseCount('incidents', 1);
        $this->assertDatabaseCount('incident_sources', 1);
    }

    public function test_it_imports_an_authorized_browser_capture_from_ande_idempotently(): void
    {
        CarbonImmutable::setTestNow('2026-08-18 16:00:00 America/Asuncion');
        Cache::clear();
        config()->set('alertapy.ingestion.local_rules_publish', true);
        $this->seed([CategorySeeder::class, SourceSeeder::class, LocationSeeder::class]);
        Location::query()->insert([
            [
                'type' => 'city',
                'name' => 'Caacupé, Cordillera',
                'normalized_name' => 'caacupe, cordillera',
                'latitude' => -25.3864,
                'longitude' => -57.1415,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'type' => 'department',
                'name' => 'Departamento de Cordillera',
                'normalized_name' => 'departamento de cordillera',
                'latitude' => -25.2500,
                'longitude' => -57.0000,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
        $path = storage_path('app/private/ande-test-archive.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode([
            'source' => 'https://www.ande.gov.py/noticias.php?tipo_nota=trabajo_programado',
            'items' => [[
                'id' => '15471',
                'title' => 'Trabajos programados en la Región Central para el Martes, 18 de agosto de 2026',
                'href' => 'https://www.ande.gov.py/interna.php?id=15471',
                'html' => file_get_contents(base_path('tests/Fixtures/ande-detail.html')),
            ]],
        ], JSON_THROW_ON_ERROR));

        try {
            $this->artisan('alertapy:scrape', [
                'source' => 'ande',
                '--force' => true,
                '--archive' => $path,
            ])->assertSuccessful();
            $this->assertDatabaseCount('raw_items', 2);
            $this->assertDatabaseCount('incidents', 2);

            Source::query()->where('slug', 'ande')->update(['last_run_at' => null]);
            $this->artisan('alertapy:scrape', [
                'source' => 'ande',
                '--force' => true,
                '--archive' => $path,
            ])->assertSuccessful();
            $this->assertDatabaseCount('raw_items', 2);
            $this->assertDatabaseCount('incidents', 2);
        } finally {
            File::delete($path);
        }
    }

    public function test_ande_zones_in_the_same_city_and_day_share_one_marker(): void
    {
        CarbonImmutable::setTestNow('2026-08-18 16:00:00 America/Asuncion');
        Cache::clear();
        config()->set('alertapy.ingestion.local_rules_publish', true);
        $this->seed([CategorySeeder::class, SourceSeeder::class, LocationSeeder::class]);
        Location::query()->create([
            'type' => 'city',
            'name' => 'Capiatá, Central',
            'normalized_name' => 'capiata, central',
            'latitude' => -25.3548,
            'longitude' => -57.4427,
        ]);
        $path = storage_path('app/private/ande-test-deduplication.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode([
            'source' => 'https://www.ande.gov.py/noticias.php?tipo_nota=trabajo_programado',
            'items' => [[
                'id' => '20000',
                'title' => 'Trabajos programados para el 18 de agosto de 2026',
                'href' => 'https://www.ande.gov.py/interna.php?id=20000',
                'html' => '<h1>Trabajos programados para el 18 de agosto de 2026</h1>
                    ZONA 01: Barrio Uno - Capiatá.
                    HORARIO DE TRABAJO: De 08:00 a 10:00 horas.
                    ACTIVIDAD: Mantenimiento uno.
                    UNIDAD RESPONSABLE: ANDE.
                    ZONA 02: Barrio Dos - Capiatá.
                    HORARIO DE TRABAJO: De 14:00 a 17:00 horas.
                    ACTIVIDAD: Mantenimiento dos.
                    UNIDAD RESPONSABLE: ANDE.',
            ]],
        ], JSON_THROW_ON_ERROR));

        try {
            $this->artisan('alertapy:scrape', [
                'source' => 'ande',
                '--force' => true,
                '--archive' => $path,
            ])->assertSuccessful();

            $this->assertDatabaseCount('raw_items', 2);
            $this->assertDatabaseCount('incidents', 1);
            $this->assertDatabaseCount('incident_sources', 2);
        $this->assertDatabaseCount('incident_updates', 2);
        $this->assertDatabaseHas('incident_updates', ['event_type' => 'detected']);
        $this->assertDatabaseHas('incident_updates', ['event_type' => 'updated']);
            $incident = Incident::query()->firstOrFail();
            $this->assertSame('Trabajos programados de ANDE en Capiatá', $incident->title);
            $this->assertStringContainsString('Barrio Uno', $incident->summary);
            $this->assertStringContainsString('Barrio Dos', $incident->summary);
        } finally {
            File::delete($path);
        }
    }
}
