<?php

namespace Tests\Unit\Collectors;

use App\Collectors\RegionalPressCollector;
use App\Enums\SourceType;
use App\Models\Source;
use Carbon\CarbonImmutable;
use Database\Seeders\LocationSeeder;
use Database\Seeders\RegionalPressSourceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RegionalPressCollectorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(LocationSeeder::class);
        CarbonImmutable::setTestNow('2026-08-19 08:00:00 America/Asuncion');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_database_configured_regional_source_publishes_only_title_and_location(): void
    {
        $source = Source::query()->create([
            'name' => 'Medio regional de prueba',
            'slug' => 'regional-test',
            'type' => SourceType::Press,
            'base_url' => 'https://regional.test/',
            'domain' => 'regional.test',
            'enabled' => true,
            'polling_minutes' => 15,
            'configuration_json' => [
                'collector' => 'regional_press',
                'regional_department' => 'Amambay',
                'listing_urls' => ['https://regional.test/'],
                'prefilter_requires_location' => false,
                'summary_title_only' => true,
            ],
        ]);
        Http::fake([
            'regional.test/' => Http::response('<h2><a href="https://regional.test/operativo-pjc">Operativo policial con detenidos en Pedro Juan Caballero</a></h2>'),
            'regional.test/operativo-pjc' => Http::response('<meta property="article:published_time" content="2026-08-19T07:00:00-03:00"><meta name="description" content="Nombre y otros datos que no deben replicarse.">'),
        ]);

        $candidate = app(RegionalPressCollector::class)->collect($source)->sole();

        $this->assertSame($candidate->title, $candidate->content);
        $this->assertSame('Pedro Juan Caballero', $candidate->metadata['city']);
        $this->assertSame('Amambay', $candidate->metadata['regional_department']);
        $this->assertTrue($candidate->metadata['privacy_limited']);
    }

    public function test_frontera_seca_local_health_story_enters_the_public_health_layer(): void
    {
        CarbonImmutable::setTestNow('2026-10-23 12:00:00 America/Asuncion');
        $definition = collect(RegionalPressSourceSeeder::definitions())
            ->firstWhere('slug', 'frontera-seca-news');
        $this->assertIsArray($definition);
        $source = Source::query()->create($definition);
        $articleUrl = 'https://fronterasecanews.com/title-1082/';

        Http::fake([
            'fronterasecanews.com/' => Http::response(
                '<article><h2><a href="'.$articleUrl.'">Octubre Rosa: Universidad Interamericana promueve prevención del cáncer de mama</a></h2></article>',
            ),
            'fronterasecanews.com/title-1082/' => Http::response(
                '<html><head>'
                .'<meta property="article:published_time" content="2026-10-14T09:34:01-03:00">'
                .'<meta property="og:title" content="Octubre Rosa: Universidad Interamericana promueve prevención del cáncer de mama">'
                .'<meta property="og:description" content="La Universidad Interamericana de Pedro Juan Caballero lanza una campaña de prevención y detección temprana del cáncer de mama.">'
                .'</head><body><h1>Octubre Rosa: Universidad Interamericana promueve prevención del cáncer de mama</h1>'
                .'<div class="entry-content"><p>La iniciativa promueve la prevención y detección temprana en Pedro Juan Caballero.</p></div>'
                .'</body></html>',
            ),
        ]);

        $candidate = app(RegionalPressCollector::class)->collect($source)->sole();

        $this->assertSame($articleUrl, $candidate->originalUrl);
        $this->assertSame('health_public', $candidate->metadata['category_slug']);
        $this->assertSame('Pedro Juan Caballero', $candidate->metadata['city']);
        $this->assertSame('Amambay', $candidate->metadata['department']);
        $this->assertSame('Octubre Rosa: Universidad Interamericana promueve prevención del cáncer de mama', $candidate->title);
    }

}
