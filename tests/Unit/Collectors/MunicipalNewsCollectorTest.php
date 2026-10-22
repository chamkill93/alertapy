<?php

namespace Tests\Unit\Collectors;

use App\Collectors\MunicipalNewsCollector;
use App\Enums\SourceType;
use App\Models\Source;
use Carbon\CarbonImmutable;
use Database\Seeders\LocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MunicipalNewsCollectorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(LocationSeeder::class);
        CarbonImmutable::setTestNow('2026-08-19 09:00:00 America/Asuncion');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_municipal_connector_requires_real_date_category_and_city(): void
    {
        $source = Source::query()->create([
            'name' => 'Municipalidad de Asunción',
            'slug' => 'municipal-test',
            'type' => SourceType::Official,
            'base_url' => 'https://municipal.test/',
            'domain' => 'municipal.test',
            'enabled' => true,
            'polling_minutes' => 15,
            'configuration_json' => [
                'collector' => 'municipal',
                'municipal_city' => 'Asunción',
                'listing_urls' => ['https://municipal.test/obras'],
                'prefilter_requires_location' => false,
                'summary_title_only' => true,
            ],
        ]);
        Http::fake([
            'municipal.test/obras' => Http::response('<h2><a href="https://municipal.test/san-estanislao">Comienzan obras en la calle San Estanislao</a></h2>'),
            'municipal.test/san-estanislao' => Http::response('<meta property="article:published_time" content="2026-08-18T08:00:00-03:00"><meta name="description" content="La intervención vial se realiza en Asunción.">'),
        ]);

        $candidate = app(MunicipalNewsCollector::class)->collect($source)->sole();

        $this->assertSame('roads', $candidate->metadata['category_slug']);
        $this->assertSame('Asunción', $candidate->metadata['city']);
        $this->assertSame($candidate->title, $candidate->content);
    }
}
