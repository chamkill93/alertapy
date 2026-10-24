<?php

namespace Tests\Unit\Collectors;

use App\Collectors\RegionalPressCollector;
use App\Enums\SourceType;
use App\Models\Source;
use Carbon\CarbonImmutable;
use Database\Seeders\LocationSeeder;
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



    public function test_regional_source_expands_incident_locations_reported_in_the_article_body(): void
    {
        CarbonImmutable::setTestNow('2026-10-24 21:00:00 America/Asuncion');
        $source = Source::query()->create([
            'name' => 'Itapúa Noticias',
            'slug' => 'itapua-noticias',
            'type' => SourceType::Press,
            'base_url' => 'https://itapuanoticias.tv/',
            'domain' => 'itapuanoticias.tv',
            'enabled' => true,
            'polling_minutes' => 30,
            'configuration_json' => [
                'collector' => 'regional_press',
                'regional_department' => 'Itapúa',
                'listing_urls' => ['https://itapuanoticias.tv/'],
                'prefilter_requires_location' => false,
                'location_search_article_body' => true,
                'summary_title_only' => true,
            ],
        ]);
        $url = 'https://itapuanoticias.tv/temporal-sin-luz-en-itapua/';
        $title = 'Temporal dejó a varias comunidades sin energía eléctrica en Itapúa';
        Http::preventStrayRequests();
        Http::fake([
            'itapuanoticias.tv/' => Http::response('<article><h2><a href="'.$url.'">'.$title.'</a></h2></article>'),
            $url => Http::response('<meta property="og:title" content="'.$title.'">'
                .'<meta property="og:description" content="El temporal afectó a Coronel Bogado, General Delgado y San Juan del Paraná.">'
                .'<meta property="article:published_time" content="2026-10-24T12:00:00-03:00">'
                .'<main><article><p>El temporal afectó a Coronel Bogado, General Delgado y San Juan del Paraná.</p></article></main>'),
        ]);

        $candidates = app(RegionalPressCollector::class)->collect($source);
        $expectedCities = ['Coronel Bogado', 'General Delgado', 'San Juan del Paraná'];

        $this->assertSame($expectedCities, $candidates->where('metadata.category_slug', 'electricity')->pluck('metadata.city')->values()->all());
        $this->assertSame($expectedCities, $candidates->where('metadata.category_slug', 'weather_flood')->pluck('metadata.city')->values()->all());
        $this->assertTrue($candidates->every(fn ($candidate): bool => $candidate->metadata['department'] === 'Itapúa'
            && $candidate->originalUrl === $url));
    }

}
