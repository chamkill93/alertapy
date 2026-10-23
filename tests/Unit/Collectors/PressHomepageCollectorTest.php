<?php

namespace Tests\Unit\Collectors;

use App\Collectors\PressHomepageCollector;
use App\Enums\SourceType;
use App\Models\Source;
use Carbon\CarbonImmutable;
use Database\Seeders\LocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PressHomepageCollectorTest extends TestCase
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

    public function test_it_collects_recent_chaco_territorial_news_and_ignores_unrelated_articles(): void
    {
        CarbonImmutable::setTestNow('2026-08-18 21:00:00 America/Asuncion');
        config()->set('alertapy.sources.ultimahora.listing_urls', ['https://www.ultimahora.com/']);
        $source = Source::query()->create([
            'name' => 'Última Hora',
            'slug' => 'ultimahora',
            'type' => SourceType::Press,
            'base_url' => 'https://www.ultimahora.com/',
            'domain' => 'ultimahora.com',
            'enabled' => true,
            'polling_minutes' => 5,
        ]);
        Http::fake([
            'www.ultimahora.com/' => Http::response('<html><body>
                <a href="https://www.ultimahora.com/organizaciones-ayoreo-rechazan-proyectos-extractivos-en-el-parque-nacional-medanos-del-chaco">
                    <h2>Organizaciones ayoreo rechazan proyectos extractivos en el Parque Nacional Médanos del Chaco</h2>
                </a>
                <a href="https://www.ultimahora.com/agenda-cultural-en-asuncion">
                    <h2>Agenda cultural para este fin de semana en Asunción</h2>
                </a>
            </body></html>'),
            'www.ultimahora.com/organizaciones-ayoreo-*' => Http::response('<html><head>
                <meta property="og:title" content="Organizaciones ayoreo rechazan proyectos extractivos en el Parque Nacional Médanos del Chaco">
                <meta property="og:description" content="Comunidades indígenas alertan sobre el impacto ambiental del proyecto.">
                <meta property="article:published_time" content="2026-08-18T23:08:42.041">
            </head></html>'),
        ]);

        $candidates = app(PressHomepageCollector::class)->collect($source);

        $this->assertCount(1, $candidates);
        $this->assertSame('territorial_news', $candidates[0]->metadata['category_slug']);
        $this->assertSame('Parque Nacional Médanos del Chaco', $candidates[0]->detectedLocation);
        $this->assertSame('Boquerón', $candidates[0]->metadata['department']);
        $this->assertSame('approximate', $candidates[0]->metadata['location_accuracy']);
        $this->assertSame('2026-08-18T20:08:42-03:00', $candidates[0]->publishedAt?->toIso8601String());
    }

    public function test_unlocated_press_incidents_use_asuncion_only_as_a_map_reference(): void
    {
        CarbonImmutable::setTestNow('2026-08-18 21:00:00 America/Asuncion');
        config()->set('alertapy.sources.ultimahora.listing_urls', ['https://www.ultimahora.com/']);
        $source = new Source([
            'name' => 'Última Hora', 'slug' => 'ultimahora', 'type' => SourceType::Press,
            'base_url' => 'https://www.ultimahora.com/', 'domain' => 'ultimahora.com',
        ]);
        $title = 'Incendio deja daños materiales y varias familias evacuadas';
        Http::fake([
            'www.ultimahora.com/' => Http::response('<h2><a href="https://www.ultimahora.com/incendio-sin-lugar">'.$title.'</a></h2>'),
            'www.ultimahora.com/incendio-sin-lugar' => Http::response('<meta property="og:title" content="'.$title.'"><meta property="og:description" content="Bomberos controlaron las llamas y asistieron a las familias afectadas."><meta property="article:published_time" content="2026-08-18T22:00:00Z">'),
        ]);

        $candidate = app(PressHomepageCollector::class)->collect($source)->sole();

        $this->assertSame('Asunción', $candidate->detectedLocation);
        $this->assertSame('fire', $candidate->metadata['category_slug']);
        $this->assertTrue($candidate->metadata['location_is_fallback']);
        $this->assertNull($candidate->metadata['city']);
        $this->assertNull($candidate->metadata['department']);
        $this->assertSame('approximate', $candidate->metadata['location_accuracy']);
        $this->assertStringContainsString('Ubicación no informada', $candidate->metadata['location_text']);
        $this->assertSame(-25.2800459, $candidate->latitude);
        $this->assertSame(-57.6343814, $candidate->longitude);
    }

    public function test_thematic_listings_share_the_global_link_budget_and_duplicate_urls_are_fetched_once(): void
    {
        CarbonImmutable::setTestNow('2026-08-18 21:00:00 America/Asuncion');
        config()->set('alertapy.sources.ultimahora.listing_urls', [
            'https://www.ultimahora.com/',
            'https://www.ultimahora.com/ande',
            'https://www.ultimahora.com/essap',
        ]);
        config()->set('alertapy.sources.ultimahora.max_links', 4);
        $source = new Source([
            'name' => 'Última Hora', 'slug' => 'ultimahora', 'type' => SourceType::Press,
            'base_url' => 'https://www.ultimahora.com/', 'domain' => 'ultimahora.com',
        ]);
        $titles = [
            'fire-one' => 'Incendio en una vivienda de Luque deja daños',
            'fire-two' => 'Incendio en una vivienda de Capiatá deja daños',
            'fire-three' => 'Incendio en una vivienda de Limpio deja daños',
            'fire-four' => 'Incendio en una vivienda de Ñemby deja daños',
            'power' => 'Cortes de energía en Luque afectan a varias familias',
            'water' => 'Familias de Luque reportan falta de agua potable',
        ];
        $listing = fn (array $ids): string => implode('', array_map(
            fn (string $id): string => '<a href="https://www.ultimahora.com/'.$id.'">'.$titles[$id].'</a>',
            $ids,
        ));
        Http::preventStrayRequests();
        Http::fake(function ($request) use ($listing, $titles) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            $listings = [
                '/' => ['fire-one', 'fire-two', 'fire-three', 'fire-four'],
                '/ande' => ['fire-one', 'power'],
                '/essap' => ['water'],
            ];
            if (isset($listings[$path])) {
                return Http::response($listing($listings[$path]));
            }
            $title = $titles[ltrim($path, '/')];

            return Http::response('<meta property="og:title" content="'.$title.'"><meta property="article:published_time" content="2026-08-18T22:00:00Z">');
        });

        $candidates = app(PressHomepageCollector::class)->collect($source);

        $this->assertSame(['fire-one', 'water', 'fire-two', 'power'], $candidates->map(
            fn ($candidate) => basename($candidate->originalUrl),
        )->all());
        Http::assertSentCount(7);
        $this->assertCount(1, Http::recorded(fn ($request) => $request->url() === 'https://www.ultimahora.com/fire-one'));
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/fire-three'));
    }

    public function test_empty_thematic_listings_return_the_unused_budget_to_other_listings(): void
    {
        CarbonImmutable::setTestNow('2026-08-18 21:00:00 America/Asuncion');
        config()->set('alertapy.sources.ultimahora.listing_urls', ['https://www.ultimahora.com/', 'https://www.ultimahora.com/essap']);
        config()->set('alertapy.sources.ultimahora.max_links', 3);
        config()->set('alertapy.sources.ultimahora.max_candidates', 2);
        $source = new Source([
            'name' => 'Última Hora', 'slug' => 'ultimahora', 'type' => SourceType::Press,
            'base_url' => 'https://www.ultimahora.com/', 'domain' => 'ultimahora.com',
        ]);
        Http::fake([
            'www.ultimahora.com/' => Http::response(implode('', array_map(
                fn (int $id): string => '<a href="https://www.ultimahora.com/article-'.$id.'">Cortes de energía en Luque: sector '.$id.'</a>',
                range(1, 4),
            ))),
            'www.ultimahora.com/essap' => Http::response('<html><body>Sin resultados</body></html>'),
            'www.ultimahora.com/article-*' => Http::response('<meta property="og:title" content="Cortes de energía en Luque afectan viviendas"><meta property="article:published_time" content="2026-08-18T22:00:00Z">'),
        ]);

        $candidates = app(PressHomepageCollector::class)->collect($source);

        $this->assertCount(2, $candidates);
        Http::assertSentCount(4);
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/article-3'));
    }

    public function test_a_failed_thematic_listing_is_not_hidden_by_a_full_homepage(): void
    {
        config()->set('alertapy.sources.ultimahora.listing_urls', ['https://www.ultimahora.com/', 'https://www.ultimahora.com/essap']);
        config()->set('alertapy.sources.ultimahora.max_links', 1);
        $source = new Source([
            'name' => 'Última Hora', 'slug' => 'ultimahora', 'type' => SourceType::Press,
            'base_url' => 'https://www.ultimahora.com/', 'domain' => 'ultimahora.com',
        ]);
        Http::fake([
            'www.ultimahora.com/' => Http::response('<a href="https://www.ultimahora.com/power">Cortes de energía en Luque afectan viviendas</a>'),
            'www.ultimahora.com/essap' => Http::response('Unavailable', 503),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('HTTP 503');
        app(PressHomepageCollector::class)->collect($source);
    }

    public function test_official_pages_can_find_the_location_in_article_metadata(): void
    {
        CarbonImmutable::setTestNow('2026-08-18 21:00:00 America/Asuncion');
        config()->set('alertapy.sources.mopc', [
            'listing_urls' => ['https://mopc.gov.py/noticias/'],
            'prefilter_requires_location' => false,
            'max_age_hours' => 168,
            'max_links' => 20,
            'max_candidates' => 10,
        ]);
        $source = Source::query()->create([
            'name' => 'MOPC',
            'slug' => 'mopc',
            'type' => SourceType::Official,
            'base_url' => 'https://mopc.gov.py/',
            'domain' => 'mopc.gov.py',
            'enabled' => true,
            'polling_minutes' => 30,
        ]);
        Http::fake([
            'mopc.gov.py/noticias/' => Http::response('<html><body>
                <h2><a href="https://mopc.gov.py/obra-ruta-py02/">Obra de rehabilitación de la Ruta PY02 avanza por tramos</a></h2>
            </body></html>'),
            'mopc.gov.py/obra-ruta-py02/' => Http::response('<html><body><main>
                <h1>Obra de rehabilitación de la Ruta PY02 avanza por tramos</h1>
                <p>Los trabajos se ejecutan en la ciudad de Luque.</p>
                <p><strong>Fecha:</strong> 18 de agosto de 2026</p>
            </main></body></html>'),
        ]);

        $candidates = app(PressHomepageCollector::class)->collect($source);

        $this->assertCount(1, $candidates);
        $this->assertSame('roads', $candidates[0]->metadata['category_slug']);
        $this->assertSame('Luque', $candidates[0]->metadata['city']);
        $this->assertSame(SourceType::Official, $candidates[0]->sourceType);
    }

    public function test_press_keeps_event_headlines_when_the_city_is_only_in_the_article(): void
    {
        CarbonImmutable::setTestNow('2026-08-18 21:00:00 America/Asuncion');
        config()->set('alertapy.sources.ultimahora.listing_urls', ['https://www.ultimahora.com/']);
        $source = Source::query()->create([
            'name' => 'Última Hora', 'slug' => 'ultimahora', 'type' => SourceType::Press,
            'base_url' => 'https://www.ultimahora.com/', 'domain' => 'ultimahora.com',
            'enabled' => true, 'polling_minutes' => 5,
        ]);
        Http::fake([
            'www.ultimahora.com/' => Http::response('<h2><a href="https://www.ultimahora.com/choque-con-heridos">Choque entre un camión y una motocicleta deja dos heridos</a></h2>'),
            'www.ultimahora.com/choque-con-heridos' => Http::response('<html><head>
                <meta property="og:title" content="Choque entre un camión y una motocicleta deja dos heridos">
                <meta property="og:description" content="Los heridos fueron auxiliados tras el accidente.">
                <meta property="article:published_time" content="2026-08-18T22:00:00Z">
                </head><body><article><p>El choque ocurrió en la ciudad de Luque.</p></article></body></html>'),
        ]);

        $candidates = app(PressHomepageCollector::class)->collect($source);
        $this->assertCount(1, $candidates);
        $this->assertSame('Luque', $candidates[0]->metadata['city']);
        $this->assertSame('traffic_accident', $candidates[0]->metadata['category_slug']);
        $this->assertStringContainsString('Luque', $candidates[0]->metadata['location_evidence']);
    }

    public function test_it_creates_separate_linked_candidates_for_a_joint_electricity_and_water_notice(): void
    {
        CarbonImmutable::setTestNow('2026-10-22 15:00:00 America/Asuncion');
        $source = new Source([
            'name' => 'Ahora Paraguay', 'slug' => 'ahora-paraguay', 'type' => SourceType::Press,
            'base_url' => 'https://ahoraparaguay.com/', 'domain' => 'ahoraparaguay.com',
            'configuration_json' => [
                'listing_urls' => ['https://ahoraparaguay.com/'],
                'prefilter_requires_location' => true,
                'location_search_article_body' => false,
            ],
        ]);
        $title = 'Cortes programados de energía y agua afectarán a Asunción este viernes';
        $url = 'https://ahoraparaguay.com/cortes-de-energia-y-agua/';
        $description = 'Los trabajos de ANDE y ESSAP afectarán a usuarios de Asunción.';
        Http::fake([
            'ahoraparaguay.com/' => Http::response('<h2><a href="'.$url.'">'.$title.'</a></h2>'),
            $url => Http::response('<meta property="og:title" content="'.$title.'"><meta property="og:description" content="'.$description.'"><meta property="article:published_time" content="2026-10-22T12:00:00-03:00"><article><p>'.$description.'</p></article>'),
        ]);

        $candidates = app(PressHomepageCollector::class)->collect($source);

        $this->assertSame(['electricity', 'water'], $candidates->map(fn ($candidate) => $candidate->metadata['category_slug'])->all());
        $this->assertSame([$url, $url], $candidates->pluck('originalUrl')->all());
        $this->assertNotSame($candidates[0]->externalIdentifier, $candidates[1]->externalIdentifier);
    }

    public function test_press_scraping_can_fill_the_air_quality_incident_layer_without_faking_station_readings(): void
    {
        CarbonImmutable::setTestNow('2026-10-22 15:00:00 America/Asuncion');
        $source = new Source([
            'name' => 'Prensa de Paraguay', 'slug' => 'press-air-quality', 'type' => SourceType::Press,
            'base_url' => 'https://press.test/', 'domain' => 'press.test',
            'configuration_json' => [
                'listing_urls' => ['https://press.test/'],
                'prefilter_requires_location' => true,
            ],
        ]);
        $title = 'Contaminación atmosférica supera límites sanitarios en Asunción';
        $url = 'https://press.test/contaminacion-atmosferica-asuncion/';
        Http::preventStrayRequests();
        Http::fake([
            'press.test/' => Http::response('<h2><a href="'.$url.'">'.$title.'</a></h2>'),
            $url => Http::response('<meta property="og:title" content="'.$title.'"><meta property="og:description" content="La medición reportó valores superiores a los límites en Asunción."><meta property="article:published_time" content="2026-10-22T12:00:00-03:00">'),
        ]);

        $candidates = app(PressHomepageCollector::class)->collect($source);

        $this->assertCount(1, $candidates);
        $this->assertSame('air_quality', $candidates->sole()->metadata['category_slug']);
        $this->assertSame($url, $candidates->sole()->originalUrl);
        $this->assertSame('Asunción', $candidates->sole()->metadata['city']);
    }

    public function test_new_sources_do_not_geolocate_a_weather_headline_from_an_incidental_body_mention(): void
    {
        CarbonImmutable::setTestNow('2026-10-22 15:00:00 America/Asuncion');
        $source = new Source([
            'name' => 'El Nacional', 'slug' => 'elnacional', 'type' => SourceType::Press,
            'base_url' => 'https://elnacional.com.py/', 'domain' => 'elnacional.com.py',
            'configuration_json' => [
                'listing_urls' => ['https://elnacional.com.py/'],
                'prefilter_requires_location' => true,
                'location_search_article_body' => false,
            ],
        ]);
        Http::fake([
            'elnacional.com.py/' => Http::response('<h2><a href="https://elnacional.com.py/editorial/">Navegando en tormenta a ciegas</a></h2>'),
            'elnacional.com.py/editorial/' => Http::response('<meta property="og:title" content="Navegando en tormenta a ciegas"><meta property="article:published_time" content="2026-10-22T12:00:00-03:00"><article><p>Columna de opinión. La autora reside en Pilar.</p></article>'),
        ]);

        $this->assertCount(0, app(PressHomepageCollector::class)->collect($source));
    }

    public function test_press_does_not_publish_future_dated_articles(): void
    {
        CarbonImmutable::setTestNow('2026-08-18 21:00:00 America/Asuncion');
        config()->set('alertapy.sources.ultimahora.listing_urls', ['https://www.ultimahora.com/']);
        $source = Source::query()->create([
            'name' => 'Última Hora', 'slug' => 'ultimahora', 'type' => SourceType::Press,
            'base_url' => 'https://www.ultimahora.com/', 'domain' => 'ultimahora.com',
            'enabled' => true, 'polling_minutes' => 5,
        ]);
        Http::fake([
            'www.ultimahora.com/' => Http::response('<h2><a href="https://www.ultimahora.com/accidente">Accidente de tránsito en Luque deja heridos</a></h2>'),
            'www.ultimahora.com/accidente' => Http::response('<meta property="og:title" content="Accidente de tránsito en Luque deja heridos"><meta property="article:published_time" content="2026-08-20T22:00:00Z">'),
        ]);
        $this->assertCount(0, app(PressHomepageCollector::class)->collect($source));
    }

    public function test_a_published_notice_keeps_the_explicit_future_cut_separate_from_publication(): void
    {
        CarbonImmutable::setTestNow('2026-10-22 15:00:00 America/Asuncion');
        config()->set('alertapy.sources.lanacion.listing_urls', ['https://www.lanacion.com.py/tag/ande/']);
        $source = new Source([
            'name' => 'La Nación', 'slug' => 'lanacion', 'type' => SourceType::Press,
            'base_url' => 'https://www.lanacion.com.py/', 'domain' => 'lanacion.com.py',
        ]);
        $title = 'Anuncian que el Palacio de Justicia quedará sin energía eléctrica este sábado durante tres horas';
        $description = 'El suministro de energía eléctrica en las dos torres del Palacio de Justicia de Asunción será interrumpido este sábado 24 de octubre, de 08:00 a 11:00, debido a tareas de mantenimiento en la Estación Puerto Sajonia.';
        Http::fake([
            'www.lanacion.com.py/tag/ande/' => Http::response('<h2><a href="https://www.lanacion.com.py/corte-justicia/">'.$title.'</a></h2>'),
            'www.lanacion.com.py/corte-justicia/' => Http::response('<meta property="og:title" content="'.$title.'"><meta property="og:description" content="'.$description.'"><script type="application/ld+json">{"datePublished":"2026-10-21T00:15:00Z"}</script><article><p>'.$description.'</p></article>'),
        ]);

        $candidates = app(PressHomepageCollector::class)->collect($source);

        $this->assertCount(1, $candidates);
        $this->assertSame('2026-10-20T21:15:00-03:00', $candidates[0]->publishedAt->toIso8601String());
        $this->assertSame('2026-10-24T08:00:00-03:00', $candidates[0]->metadata['starts_at']);
        $this->assertSame('2026-10-24T11:00:00-03:00', $candidates[0]->metadata['ends_at']);
        $this->assertSame('2026-10-24T08:00:00-03:00', $candidates[0]->metadata['occurred_at']);
    }

    public function test_it_reads_relative_spanish_listing_dates_for_recent_regional_health_news(): void
    {
        CarbonImmutable::setTestNow('2026-10-23 12:00:00 America/Asuncion');
        $listingUrl = 'https://www.visioncomunicaciones.com.py/blog/categories/villarrica';
        $articleUrl = 'https://www.visioncomunicaciones.com.py/post/tamizaje-tuberculosis-villarrica';
        $title = 'Tamizaje para detectar tuberculosis en la cárcel: realizan pruebas a cerca de 600 internos hasta el viernes';
        $description = 'Salud Pública y el Ministerio de Justicia realizan una jornada de tamizaje para detectar tuberculosis en la Penitenciaría de Villarrica.';
        $source = new Source([
            'name' => 'Visión Comunicaciones',
            'slug' => 'vision-comunicaciones',
            'type' => SourceType::Press,
            'base_url' => 'https://www.visioncomunicaciones.com.py/',
            'domain' => 'visioncomunicaciones.com.py',
            'enabled' => true,
            'polling_minutes' => 30,
            'configuration_json' => [
                'listing_urls' => [$listingUrl],
                'prefilter_requires_location' => false,
                'max_age_hours' => 168,
                'max_links' => 18,
                'max_candidates' => 12,
                'location_search_article_body' => true,
                'summary_title_only' => true,
                'regional_department' => 'Guairá',
            ],
        ]);
        Http::fake([
            $listingUrl => Http::response('<div><h2><a href="'.$articleUrl.'">'.$title.'</a></h2><span>hace 3 horas 1 min de lectura</span></div>'),
            $articleUrl => Http::response('<meta property="og:title" content="'.$title.'"><meta property="og:description" content="'.$description.'"><article><p>'.$description.'</p></article>'),
        ]);

        $candidate = app(PressHomepageCollector::class)->collect($source)->sole();

        $this->assertSame($articleUrl, $candidate->originalUrl);
        $this->assertSame('health_public', $candidate->metadata['category_slug']);
        $this->assertSame('Villarrica', $candidate->metadata['city']);
        $this->assertSame('2026-10-23T09:00:00-03:00', $candidate->publishedAt->toIso8601String());
        Http::assertSentCount(2);
    }

    public function test_a_newsroom_address_in_the_footer_is_not_an_event_location(): void
    {
        CarbonImmutable::setTestNow('2026-08-18 21:00:00 America/Asuncion');
        config()->set('alertapy.sources.ultimahora.listing_urls', ['https://www.ultimahora.com/']);
        $source = Source::query()->create([
            'name' => 'Última Hora', 'slug' => 'ultimahora', 'type' => SourceType::Press,
            'base_url' => 'https://www.ultimahora.com/', 'domain' => 'ultimahora.com',
            'enabled' => true, 'polling_minutes' => 5,
        ]);
        Http::fake([
            'www.ultimahora.com/' => Http::response('<h2><a href="https://www.ultimahora.com/accidente">Accidente de tránsito deja dos heridos</a></h2>'),
            'www.ultimahora.com/accidente' => Http::response('<meta property="og:title" content="Accidente de tránsito deja dos heridos"><meta property="og:description" content="Los heridos fueron auxiliados."><meta property="article:published_time" content="2026-08-18T22:00:00Z"><footer><p>Redacción: Asunción, Paraguay.</p></footer>'),
        ]);
        $candidate = app(PressHomepageCollector::class)->collect($source)->sole();
        $this->assertTrue($candidate->metadata['location_is_fallback']);
        $this->assertNull($candidate->metadata['city']);
        $this->assertNull($candidate->metadata['department']);
    }

    public function test_it_never_interprets_a_missing_publication_date_as_now(): void
    {
        CarbonImmutable::setTestNow('2026-08-18 21:00:00 America/Asuncion');
        config()->set('alertapy.sources.mopc', [
            'listing_urls' => ['https://mopc.gov.py/noticias/'],
            'prefilter_requires_location' => false,
            'max_age_hours' => 168,
        ]);
        $source = Source::query()->create([
            'name' => 'MOPC',
            'slug' => 'mopc',
            'type' => SourceType::Official,
            'base_url' => 'https://mopc.gov.py/',
            'domain' => 'mopc.gov.py',
            'enabled' => true,
            'polling_minutes' => 30,
        ]);
        Http::fake([
            'mopc.gov.py/noticias/' => Http::response('<h2><a href="https://mopc.gov.py/obra-sin-fecha/">Obra de reparación vial en Luque continúa</a></h2>'),
            'mopc.gov.py/obra-sin-fecha/' => Http::response('<main><p>Los trabajos viales se ejecutan en Luque.</p></main>'),
        ]);

        $this->assertCount(0, app(PressHomepageCollector::class)->collect($source));
    }

    public function test_senepa_collects_recent_territorial_vector_actions_once_and_rejects_institutional_news(): void
    {
        CarbonImmutable::setTestNow('2026-08-20 19:00:00 America/Asuncion');
        $source = Source::query()->create([
            'name' => 'SENEPA',
            'slug' => 'senepa',
            'type' => SourceType::Official,
            'base_url' => 'https://senepa.gov.py/',
            'domain' => 'senepa.gov.py',
            'enabled' => true,
            'polling_minutes' => 30,
        ]);
        $operationalUrl = 'https://senepa.gov.py/2026/08/19/operativo-de-prevencion-de-arbovirosis-en-encarnacion/';
        $archiveUrl = 'https://senepa.gov.py/2026/08/14/prevencion-y-participacion-comunitaria-en-loma-grande/';
        $institutionalUrl = 'https://senepa.gov.py/2026/08/19/taller-mecip-en-asuncion/';
        $listing = <<<HTML
            <html><body>
                <h2><a href="{$operationalUrl}">Operativo de prevención de arbovirosis moviliza a más de 100 personas en Encarnación</a></h2>
                <h2><a href="{$institutionalUrl}">SENEPA realiza taller institucional MECIP en Asunción</a></h2>
            </body></html>
            HTML;
        Http::fake([
            'senepa.gov.py/' => Http::response($listing),
            'senepa.gov.py/category/noticias/' => Http::response($listing),
            'senepa.gov.py/2026/08/' => Http::response($listing),
            'senepa.gov.py/2026/08/page/2/' => Http::response("<h2><a href=\"{$archiveUrl}\">Prevención y participación comunitaria se fortalecen en Loma Grande</a></h2>"),
            'senepa.gov.py/2026/07/' => Http::response('<html><body></body></html>'),
            $operationalUrl => Http::response('<html><head>
                <meta property="og:title" content="Operativo de prevención de arbovirosis moviliza a más de 100 personas en Encarnación">
                <meta property="og:description" content="SENEPA realizó una minga ambiental y rastrillaje en distintos barrios de Encarnación.">
                <meta property="article:published_time" content="2026-08-19T12:00:00-03:00">
            </head></html>'),
            $archiveUrl => Http::response('<html><head>
                <meta property="og:title" content="Prevención y participación comunitaria se fortalecen en Loma Grande">
                <meta property="og:description" content="SENEPA acompañó una minga ambiental y eliminación de criaderos en Loma Grande, Cordillera.">
                <meta property="article:published_time" content="2026-08-14T12:00:00-03:00">
            </head></html>'),
            $institutionalUrl => Http::response('<html><head>
                <meta property="og:title" content="SENEPA realiza taller institucional MECIP en Asunción">
                <meta property="og:description" content="Jornada sobre acuerdos éticos institucionales en Asunción.">
                <meta property="article:published_time" content="2026-08-19T12:00:00-03:00">
            </head></html>'),
        ]);

        $candidates = app(PressHomepageCollector::class)->collect($source);

        $this->assertCount(2, $candidates);
        $this->assertSame('health_vector', $candidates[0]->metadata['category_slug']);
        $this->assertSame('Encarnación', $candidates[0]->metadata['city']);
        $this->assertSame($operationalUrl, $candidates[0]->originalUrl);
        $this->assertSame($archiveUrl, $candidates[1]->originalUrl);
        $this->assertSame('health_vector', $candidates[1]->metadata['category_slug']);
    }
    public function test_independent_press_correlates_health_and_flood_layers(): void
    {
        CarbonImmutable::setTestNow('2026-10-23 12:00:00 America/Asuncion');
        $source = new Source([
            'name' => 'Prensa local',
            'slug' => 'diario-paraguayo',
            'type' => SourceType::Press,
            'base_url' => 'https://www.diarioparaguayo.com.py/',
            'domain' => 'diarioparaguayo.com',
            'configuration_json' => [
                'listing_urls' => ['https://www.diarioparaguayo.com.py/'],
                'prefilter_requires_location' => false,
                'max_age_hours' => 168,
                'max_links' => 20,
                'max_candidates' => 10,
                'location_search_article_body' => true,
            ],
        ]);
        $title = 'Equipos de salud asisten a comunidades afectadas por inundaciones en Misiones';
        $url = 'https://diarioparaguayo.com/noticia/equipos-de-salud-misiones/';
        Http::preventStrayRequests();
        Http::fake([
            'www.diarioparaguayo.com.py/' => Http::response('<h2><a href="'.$url.'">'.$title.'</a></h2>'),
            $url => Http::response('<meta property="og:title" content="'.$title.'">
                <meta property="article:published_time" content="2026-10-22T09:00:00-03:00">
                <article><p>Los equipos brindan asistencia a familias afectadas en Misiones.</p></article>'),
        ]);

        $candidates = app(PressHomepageCollector::class)->collect($source);

        $this->assertSame(
            ['weather_flood', 'health_public'],
            $candidates->pluck('metadata.category_slug')->all(),
        );
        $this->assertTrue($candidates->every(fn ($candidate): bool => $candidate->originalUrl === $url));
        $this->assertTrue($candidates->every(fn ($candidate): bool => $candidate->metadata['department'] === 'Misiones'));
    }

}
