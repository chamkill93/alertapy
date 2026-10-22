<?php

namespace Tests\Unit\Collectors;

use App\Collectors\EssapCollector;
use App\Enums\SourceType;
use App\Models\Source;
use Carbon\CarbonImmutable;
use Database\Seeders\LocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EssapCollectorTest extends TestCase
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

    public function test_it_keeps_only_official_geolocatable_water_events(): void
    {
        CarbonImmutable::setTestNow('2026-08-18 16:00:00 America/Asuncion');
        config()->set('alertapy.sources.essap.lookback_days', 7);
        $source = Source::query()->create([
            'name' => 'ESSAP',
            'slug' => 'essap',
            'type' => SourceType::Official,
            'base_url' => 'https://www.essap.com.py/',
            'domain' => 'essap.com.py',
            'enabled' => true,
            'polling_minutes' => 10,
        ]);
        Http::fake([
            'www.essap.com.py/wp-json/wp/v2/posts*' => Http::response([
                [
                    'id' => 70001,
                    'date' => '2026-08-17T09:30:00',
                    'link' => 'https://www.essap.com.py/rotura-tuberia-asuncion/',
                    'title' => ['rendered' => 'Rotura de tubería principal ocasiona falta de agua en Asunción'],
                    'excerpt' => ['rendered' => '<p>Cuadrillas de ESSAP realizan la reparación.</p>'],
                    'content' => ['rendered' => '<p>El servicio se restablecerá progresivamente.</p>'],
                    'categories' => [6],
                ],
                [
                    'id' => 70002,
                    'date' => '2026-08-16T10:00:00',
                    'link' => 'https://www.essap.com.py/capacitacion-funcionarios/',
                    'title' => ['rendered' => 'ESSAP capacita a funcionarios en Asunción'],
                    'excerpt' => ['rendered' => '<p>Una actividad institucional.</p>'],
                    'content' => ['rendered' => '<p>La capacitación se desarrolló con normalidad.</p>'],
                    'categories' => [6],
                ],
            ], 200, ['X-WP-TotalPages' => '1']),
        ]);

        $candidates = app(EssapCollector::class)->collect($source);

        $this->assertCount(1, $candidates);
        $this->assertSame('essap-70001', $candidates[0]->externalIdentifier);
        $this->assertSame('water', $candidates[0]->metadata['category_slug']);
        $this->assertSame('Asunción', $candidates[0]->detectedLocation);
        $this->assertTrue($candidates[0]->metadata['official_api']);
        Http::assertSent(fn ($request): bool => $request['categories'] === '6,7,34,35');
    }

    public function test_missing_and_future_publication_dates_are_not_treated_as_recent_incidents(): void
    {
        CarbonImmutable::setTestNow('2026-10-22 15:00:00 America/Asuncion');
        $post = [
            'id' => 1, 'link' => 'https://www.essap.com.py/corte-asuncion/',
            'title' => ['rendered' => 'Corte de agua potable en Asunción'],
            'excerpt' => ['rendered' => 'Reparación de tubería principal.'],
            'content' => ['rendered' => 'Trabajos de reparación.'],
        ];
        Http::fake(['www.essap.com.py/wp-json/wp/v2/posts*' => Http::response([
            [...$post, 'date' => ''],
            [...$post, 'id' => 2, 'date' => '2026-10-23T10:00:00'],
            [...$post, 'id' => 3, 'date' => '2026-10-22T10:00:00'],
        ])]);

        $candidates = app(EssapCollector::class)->collect(new Source(['slug' => 'essap', 'type' => SourceType::Official]));

        $this->assertCount(1, $candidates);
        $this->assertSame('essap-3', $candidates->first()->externalIdentifier);
    }
}
