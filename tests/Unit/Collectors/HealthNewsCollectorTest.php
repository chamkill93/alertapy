<?php

namespace Tests\Unit\Collectors;

use App\Collectors\MspbsCollector;
use App\Collectors\HealthNewsCollector;
use App\Enums\SourceType;
use App\Models\Source;
use Carbon\CarbonImmutable;
use Database\Seeders\LocationSeeder;
use Database\Seeders\AdditionalHealthSourceSeeder;
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

        $this->assertSame(['weather_flood', 'health_public'], $candidates->pluck('metadata.category_slug')->unique()->values()->all());
        $this->assertSame(['Ciudad del Este', 'Presidente Franco'], $candidates->pluck('metadata.city')->filter()->unique()->values()->all());
        $this->assertTrue($candidates->every(fn ($candidate): bool => $candidate->originalUrl === $storyUrl
            && preg_match('/^'.preg_quote($title, '/').'(?: Ámbito territorial: .+)?$/u', $candidate->content) === 1));
    }

    public function test_dnerhs_latest_regional_health_report_is_collected_across_all_six_regions(): void
    {
        $title = 'Talleres territoriales fortalecieron las Redes Integradas e Integrales de Servicios de Salud – RIISS y los Equipos Interprofesionales de Salud – EqIPS en seis regiones del país.';
        $articleUrl = 'https://dnerhs.mspbs.gov.py/index.php/2026/10/22/talleres-territoriales-fortalecieron-las-redes-integradas-e-integrales-de-servicios-de-salud-riiss-y-los-equipos-interprofesionales-de-salud-eqips-en-seis-regiones-del-pais/';
        $newsListing = 'https://dnerhs.mspbs.gov.py/index.php/category/dnerhs/noticias/';
        $eqipsListing = 'https://dnerhs.mspbs.gov.py/index.php/category/dnerhs/dn/dgprhs/eqips/';
        $listingHtml = '<article><h2><a href="'.$articleUrl.'">'.$title.'</a></h2><time datetime="2026-10-22">22 de octubre de 2026</time></article>';
        $articleHtml = '<meta property="og:title" content="'.$title.'">'
            .'<meta property="og:description" content="Entre junio y octubre de 2026, el MSPBS desarrolló talleres territoriales en seis regiones sanitarias del país.">'
            .'<meta property="article:published_time" content="2026-10-22T14:39:00-03:00">'
            .'<article>'
            .'<p>Las jornadas se llevaron a cabo en las Regiones Sanitarias de Itapúa, Caazapá, Paraguarí, Alto Paraná, Central y San Pedro.</p>'
            .'<p>Itapúa: Alto Verá y San Pedro del Paraná. Caazapá: Moisés Bertoni, San Juan Nepomuceno e Higinio Morínigo. Paraguarí: Pirayú, María Antonia y Mbujapey. Alto Paraná: Ciudad del Este, Itakyry y Juan E. O’Leary. Central: J. Augusto Saldívar, Ñemby, Capiatá y San Antonio. San Pedro: Santa Rosa del Aguaray, San Pedro del Ykuamanduyú y San Vicente Pancholo.</p>'
            .'</article>';

        $definition = collect(AdditionalHealthSourceSeeder::definitions())->firstWhere('slug', 'dnerhs');
        $source = new Source($definition);

        Http::preventStrayRequests();
        Http::fake([
            $newsListing => Http::response($listingHtml),
            $eqipsListing => Http::response($listingHtml),
            $articleUrl => Http::response($articleHtml),
        ]);

        $candidates = app(HealthNewsCollector::class)->collect($source);

        $this->assertSame(['health_public'], $candidates->pluck('metadata.category_slug')->unique()->values()->all());
        $this->assertEqualsCanonicalizing(
            ['Itapúa', 'Caazapá', 'Paraguarí', 'Alto Paraná', 'Central', 'San Pedro'],
            $candidates->pluck('metadata.department')->filter()->unique()->values()->all(),
        );
        $this->assertTrue($candidates->every(fn ($candidate): bool => $candidate->originalUrl === $articleUrl));
        $this->assertSame(3, Http::recorded()->count());
    }


    public function test_unlocated_official_health_news_uses_asuncion_only_as_a_map_reference(): void
    {
        $url = 'https://www.mspbs.gov.py/portal/campana-nacional-vacunacion.html';
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
            'www.mspbs.gov.py/noticias.php*' => Http::response('<h2><a href="'.$url.'">Campaña nacional de vacunación</a></h2>'),
            $url => Http::response('<meta property="og:title" content="Campaña nacional de vacunación"><meta property="article:published_time" content="2026-10-22T12:00:00-03:00"><article><p>La campaña se realizará en todo el país, sin puntos territoriales anunciados.</p></article>'),
        ]);
        $source = new Source([
            'name' => 'MSPBS',
            'slug' => 'mspbs-health',
            'type' => SourceType::Official,
            'base_url' => 'https://www.mspbs.gov.py/',
            'domain' => 'mspbs.gov.py',
            'configuration_json' => config('alertapy.sources.mspbs-health'),
        ]);

        $candidate = app(MspbsCollector::class)->collect($source)->sole();

        $this->assertSame('health_public', $candidate->metadata['category_slug']);
        $this->assertSame('Asunción', $candidate->detectedLocation);
        $this->assertTrue($candidate->metadata['location_is_fallback']);
        $this->assertNull($candidate->metadata['city']);
        $this->assertNull($candidate->metadata['department']);
        $this->assertSame(-25.2800459, $candidate->latitude);
        $this->assertSame(-57.6343814, $candidate->longitude);
    }

}
