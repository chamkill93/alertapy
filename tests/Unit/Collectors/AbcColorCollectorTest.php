<?php

namespace Tests\Unit\Collectors;

use App\Collectors\AbcColorCollector;
use App\Enums\SourceType;
use App\Models\Source;
use Carbon\CarbonImmutable;
use Database\Seeders\LocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AbcColorCollectorTest extends TestCase
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

    public function test_it_keeps_only_recent_geolocatable_territorial_events(): void
    {
        CarbonImmutable::setTestNow('2026-08-18 16:00:00 America/Asuncion');
        $source = Source::query()->create([
            'name' => 'ABC Color',
            'slug' => 'abc',
            'type' => SourceType::Press,
            'base_url' => 'https://www.abc.com.py/',
            'domain' => 'abc.com.py',
            'enabled' => true,
            'polling_minutes' => 5,
        ]);

        $candidates = app(AbcColorCollector::class)->parse(
            $source,
            file_get_contents(base_path('tests/Fixtures/abc-nacionales.xml')),
        );

        $this->assertCount(2, $candidates);
        $this->assertSame('traffic_accident', $candidates[0]->metadata['category_slug']);
        $this->assertSame('Pedrozo, Ypacaraí', $candidates[0]->detectedLocation);
        $this->assertSame('weather_flood', $candidates[1]->metadata['category_slug']);
        $this->assertSame('Departamento de Alto Paraná', $candidates[1]->detectedLocation);
        $this->assertSame('department', $candidates[1]->metadata['location_accuracy']);
    }

    public function test_an_unlocated_rss_incident_is_kept_with_an_asuncion_reference(): void
    {
        CarbonImmutable::setTestNow('2026-08-18 16:00:00 America/Asuncion');
        $source = new Source([
            'name' => 'ABC Color', 'slug' => 'abc', 'type' => SourceType::Press,
            'base_url' => 'https://www.abc.com.py/', 'domain' => 'abc.com.py',
        ]);
        $xml = '<rss><channel><item><title>Incendio deja daños materiales y familias evacuadas</title>'
            .'<link>https://www.abc.com.py/nacionales/incendio-sin-lugar/</link>'
            .'<pubDate>Tue, 18 Aug 2026 18:00:00 +0000</pubDate>'
            .'<description>Bomberos controlaron las llamas y asistieron a los vecinos.</description>'
            .'</item></channel></rss>';

        $candidate = app(AbcColorCollector::class)->parse($source, $xml)->sole();

        $this->assertSame('Asunción', $candidate->detectedLocation);
        $this->assertTrue($candidate->metadata['location_is_fallback']);
        $this->assertNull($candidate->metadata['city']);
        $this->assertNull($candidate->metadata['department']);
        $this->assertSame(-25.2800459, $candidate->latitude);
        $this->assertSame(-57.6343814, $candidate->longitude);
    }

    public function test_it_reads_the_public_archive_and_detects_a_power_outage(): void
    {
        CarbonImmutable::setTestNow('2026-08-18 16:00:00 America/Asuncion');
        config()->set('alertapy.sources.abc.lookback_days', 7);
        $source = Source::query()->create([
            'name' => 'ABC Color',
            'slug' => 'abc',
            'type' => SourceType::Press,
            'base_url' => 'https://www.abc.com.py/',
            'domain' => 'abc.com.py',
            'enabled' => true,
            'polling_minutes' => 5,
        ]);
        Http::fake([
            'www.abc.com.py/pf/api/v3/content/fetch/sections-api*' => Http::response([
                'content_elements' => [
                    [
                        '_id' => 'POWER-OUTAGE-1',
                        'canonical_url' => '/economia/2026/08/17/linea-fuera-de-servicio/',
                        'display_date' => '2026-08-17T16:43:04-03:00',
                        'headlines' => [
                            'basic' => 'Línea de baja tensión fuera de servicio deja sin luz a barrios de Mariano Roque Alonso',
                        ],
                        'description' => ['basic' => 'La avería afectó el suministro eléctrico.'],
                        'content_elements' => [
                            ['type' => 'text', 'content' => '<p>Cuadrillas trabajan en la reposición.</p>'],
                        ],
                    ],
                    [
                        '_id' => 'OLD-1',
                        'canonical_url' => '/nacionales/2026/08/01/incendio-antiguo/',
                        'display_date' => '2026-08-01T10:00:00-03:00',
                        'headlines' => ['basic' => 'Incendio antiguo en Asunción'],
                    ],
                ],
            ]),
        ]);

        $candidates = app(AbcColorCollector::class)->collect($source);

        $this->assertCount(1, $candidates);
        $this->assertSame('electricity', $candidates[0]->metadata['category_slug']);
        $this->assertSame('Mariano Roque Alonso', $candidates[0]->detectedLocation);
        $this->assertTrue($candidates[0]->metadata['archive_backfill']);
        Http::assertSentCount(2);
    }

    public function test_scheduled_collection_covers_recent_archive_once_per_section_and_preserves_dates(): void
    {
        CarbonImmutable::setTestNow('2026-10-22 15:00:00 America/Asuncion');
        config()->set('alertapy.sources.abc.max_candidates', 2);
        config()->set('alertapy.sources.abc.archive_page_size', 2);
        $source = new Source(['slug' => 'abc', 'type' => SourceType::Press, 'base_url' => 'https://www.abc.com.py/']);
        $article = fn (string $id, string $title, string $date): array => [
            'canonical_url' => '/nacionales/'.$id.'/', 'display_date' => $date,
            'headlines' => ['basic' => $title],
        ];
        Http::fake([
            'www.abc.com.py/arc/outboundfeeds/rss/nacionales/' => Http::response('<rss><channel></channel></rss>'),
            'www.abc.com.py/pf/api/v3/content/fetch/sections-api*' => Http::response(['content_elements' => [
                $article('water', 'Corte de agua afecta a Luque', '2026-10-22T12:00:00-03:00'),
                $article('river', 'Pilar: cierran compuertas ante crecida del río Paraguay', '2026-10-22T11:00:00-03:00'),
                $article('future', 'Corte de energía en Asunción', '2026-10-23T11:00:00-03:00'),
                $article('missing', 'Corte de energía en Asunción', ''),
                $article('old', 'Corte de energía en Asunción', '2026-10-19T11:00:00-03:00'),
            ]]),
        ]);

        $candidates = app(AbcColorCollector::class)->collect($source);

        $this->assertCount(2, $candidates);
        $this->assertSame(['water', 'hydrology'], $candidates->pluck('metadata.category_slug')->all());
        $this->assertSame('https://www.abc.com.py/nacionales/water/', $candidates[0]->originalUrl);
        $this->assertSame('2026-10-22 12:00:00', $candidates[0]->publishedAt->toDateTimeString());
        $this->assertFalse($candidates[0]->metadata['archive_backfill']);
        Http::assertSentCount(3);
    }
}
