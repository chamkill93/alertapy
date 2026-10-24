<?php

namespace Tests\Unit\Collectors;

use App\Collectors\MspbsCollector;
use App\Collectors\HealthNewsCollector;
use App\Enums\SourceType;
use App\Models\Source;
use Carbon\CarbonImmutable;
use Database\Seeders\LocationSeeder;
use Database\Seeders\AdditionalHealthSourceSeeder;
use Database\Seeders\SourceSeeder;
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
        $localized = $candidates->where('originalUrl', $storyUrl);
        $this->assertNotEmpty($localized);
        $this->assertTrue($localized->every(fn ($candidate): bool => preg_match('/^'.preg_quote($title, '/').'(?: Ámbito territorial: .+)?$/u', $candidate->content) === 1));
        $this->assertTrue($candidates->contains(fn ($candidate): bool => $candidate->originalUrl === $nationalUrl
            && $candidate->metadata['location_is_fallback']
            && $candidate->detectedLocation === 'Asunción'));
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


    public function test_health_category_uses_article_lead_when_open_graph_description_is_generic(): void
    {
        $url = 'https://www.mspbs.gov.py/portal/jornada-comunitaria-caaguazu.html';
        $title = 'Jornada comunitaria se realizó en Caaguazú';
        $configuration = [
            'listing_urls' => ['https://www.mspbs.gov.py/noticias.php?page=1&url=portal'],
            'prefilter_requires_location' => false,
            'summary_title_only' => true,
            'max_age_hours' => 168,
            'max_links' => 20,
            'max_candidates' => 10,
            'location_search_article_body' => true,
            'category_classifier' => 'health',
        ];
        config()->set('alertapy.sources.mspbs-health', $configuration);
        Http::preventStrayRequests();
        Http::fake([
            'www.mspbs.gov.py/noticias.php*' => Http::response('<h2><a href="'.$url.'">'.$title.'</a></h2>'),
            $url => Http::response('<meta property="og:title" content="'.$title.'">'
                .'<meta property="og:description" content="La actividad se desarrolló con participación de vecinos de la localidad.">'
                .'<meta property="article:published_time" content="2026-10-22T12:00:00-03:00">'
                .'<article><p>Equipos del MSPBS realizaron vacunación y atención médica a niños y adultos.</p>'
                .'<p>La actividad se llevó a cabo en Caaguazú.</p></article>'),
        ]);
        $source = new Source([
            'name' => 'MSPBS',
            'slug' => 'mspbs-health',
            'type' => SourceType::Official,
            'base_url' => 'https://www.mspbs.gov.py/',
            'domain' => 'mspbs.gov.py',
            'configuration_json' => $configuration,
        ]);

        $candidate = app(MspbsCollector::class)->collect($source)->sole();

        $this->assertSame('health_public', $candidate->metadata['category_slug']);
        $this->assertSame('Caaguazú', $candidate->metadata['city']);
        $this->assertSame($url, $candidate->originalUrl);
        $this->assertSame($title, $candidate->content);
    }

    public function test_ips_daily_health_story_is_collected_with_its_original_source_link(): void
    {
        $definition = collect(AdditionalHealthSourceSeeder::definitions())->firstWhere('slug', 'ips');
        $this->assertNotNull($definition);
        $source = new Source($definition);
        $articleUrl = 'https://portal.ips.gov.py/sistemas/ipsportal/noticia.php?cod=4901';
        $title = 'Presidente del IPS verifica situación del Hospital Regional de Encarnación y anuncia intervención urgente en su infraestructura';
        $listing = '<article><h2><a href="'.$articleUrl.'">'.$title.'</a></h2><time datetime="2026-10-22">22 de octubre de 2026</time></article>';
        $article = '<meta property="og:title" content="'.$title.'">'
            .'<meta property="og:description" content="El Hospital Regional del IPS en Encarnación recibirá mejoras de infraestructura.">'
            .'<meta property="article:published_time" content="2026-10-22T10:00:00-03:00">'
            .'<article><p>El presidente visitó el Hospital Regional de Encarnación, departamento de Itapúa, y anunció una intervención en su infraestructura.</p></article>';

        Http::preventStrayRequests();
        Http::fake([
            'portal.ips.gov.py/sistemas/ipsportal/noticias.php*' => Http::response($listing),
            'portal.ips.gov.py/sistemas/ipsportal/noticia.php*' => Http::response($article),
        ]);

        $candidates = app(HealthNewsCollector::class)->collect($source);

        $health = $candidates->firstWhere('metadata.category_slug', 'health_public');
        $this->assertNotNull($health);
        $this->assertSame($articleUrl, $health->originalUrl);
        $this->assertSame('Encarnación', $health->metadata['city']);
        $this->assertSame('Itapúa', $health->metadata['department']);
        $this->assertSame(4, Http::recorded()->count());
    }


    public function test_pai_dengue_vaccination_is_correlated_across_health_layers_and_uses_asuncion_reference(): void
    {
        $this->seed(SourceSeeder::class);
        $source = Source::query()->where('slug', 'pai')->firstOrFail();
        $articleUrl = 'https://pai.mspbs.gov.py/vacunacion-dengue-ya-recibiste-la-primera-dosis-no-te-olvides-de-la-segunda/';
        $title = 'Vacunación dengue: ya recibiste la primera dosis, no te olvides de la segunda';
        $listing = '<article><h2><a href="'.$articleUrl.'">'.$title.'</a></h2><time datetime="2026-10-17">17 de octubre de 2026</time></article>';
        $article = '<meta property="og:title" content="'.$title.'">'
            .'<meta property="og:description" content="El PAI recuerda completar el esquema de vacunación contra el dengue.">'
            .'<meta property="article:published_time" content="2026-10-17T10:00:00-03:00">'
            .'<article><p>El programa recuerda completar la segunda dosis y eliminar criaderos de mosquitos.</p></article>';

        Http::preventStrayRequests();
        Http::fake([
            '*pai.mspbs.gov.py/' => Http::response($listing),
            '*pai.mspbs.gov.py/category/noticias*' => Http::response($listing),
            $articleUrl => Http::response($article),
        ]);

        $candidates = app(HealthNewsCollector::class)->collect($source);

        $this->assertSame(['health_vector', 'health_public'], $candidates->pluck('metadata.category_slug')->all());
        $this->assertCount(2, $candidates);
        $this->assertTrue($candidates->every(fn ($candidate): bool => $candidate->originalUrl === $articleUrl
            && $candidate->detectedLocation === 'Asunción'
            && $candidate->metadata['location_is_fallback']
            && $candidate->metadata['city'] === null
            && $candidate->metadata['department'] === null));
    }

}
