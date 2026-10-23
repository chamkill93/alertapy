<?php

namespace Tests\Unit\Collectors;

use App\Collectors\MspbsCollector;
use App\Enums\SourceType;
use App\Models\Source;
use Carbon\CarbonImmutable;
use Database\Seeders\LocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HealthNewsCollectorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(LocationSeeder::class);
        CarbonImmutable::setTestNow('2026-10-23 12:00:00 America/Asuncion');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_mspbs_health_news_is_mapped_to_health_and_related_territorial_layers(): void
    {
        $storyUrl = 'https://www.mspbs.gov.py/portal/asistencia-por-crecidas.html';
        $nationalUrl = 'https://www.mspbs.gov.py/portal/vacunacion-nacional.html';
        $title = 'Asistencia en salud llega a familias afectadas por crecidas en Ciudad del Este y Presidente Franco';
        config()->set('alertapy.sources.mspbs-health', [
            'listing_urls' => ['https://www.mspbs.gov.py/noticias.php?page=1&url=portal'],
            'prefilter_requires_location' => false,
            'summary_title_only' => true,
            'max_age_hours' => 168,
            'max_links' => 20,
            'max_candidates' => 10,
            'location_search_article_body' => true,
            'category_classifier' => 'health',
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'www.mspbs.gov.py/noticias.php*' => Http::response('<h2><a href="'.$storyUrl.'">'.$title.'</a></h2><h2><a href="'.$nationalUrl.'">Campaña nacional de vacunación</a></h2>'),
            $storyUrl => Http::response('<meta property="og:title" content="'.$title.'"><meta property="article:published_time" content="2026-10-22T12:00:00-03:00"><article><p>Asistencia médica a familias afectadas por las crecidas en Ciudad del Este y Presidente Franco.</p></article>'),
            $nationalUrl => Http::response('<meta property="og:title" content="Campaña nacional de vacunación"><meta property="article:published_time" content="2026-10-22T12:00:00-03:00"><article><p>La campaña se realizará en todo el país, sin puntos territoriales anunciados.</p></article>'),
            'www.mspbs.gov.py/index.php' => Http::response('<a href="'.$storyUrl.'">'.$title.'</a>'),
        ]);
        $source = new Source([
            'name' => 'MSPBS',
            'slug' => 'mspbs-health',
            'type' => SourceType::Official,
            'base_url' => 'https://www.mspbs.gov.py/',
            'domain' => 'mspbs.gov.py',
            'configuration_json' => [
                'listing_urls' => ['https://www.mspbs.gov.py/noticias.php?page=1&url=portal'],
                'prefilter_requires_location' => false,
                'summary_title_only' => true,
                'max_age_hours' => 168,
                'max_links' => 20,
                'max_candidates' => 10,
                'location_search_article_body' => true,
                'category_classifier' => 'health',
            ],
        ]);

        $candidates = app(MspbsCollector::class)->collect($source);

        $this->assertSame(['health_public', 'weather_flood'], $candidates->pluck('metadata.category_slug')->unique()->values()->all());
        $this->assertSame(['Ciudad del Este', 'Presidente Franco'], $candidates->pluck('metadata.city')->unique()->values()->all());
        $this->assertTrue($candidates->every(fn ($candidate): bool => $candidate->originalUrl === $storyUrl
            && preg_match('/^'.preg_quote($title, '/').'(?: Ámbito territorial: .+)?$/u', $candidate->content) === 1));
    }
}
