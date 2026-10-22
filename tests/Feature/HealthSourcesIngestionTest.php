<?php

namespace Tests\Feature;

use App\Enums\LocationAccuracy;
use App\Models\GeocodingCache;
use Carbon\CarbonImmutable;
use Database\Seeders\CategorySeeder;
use Database\Seeders\LocationSeeder;
use Database\Seeders\SourceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HealthSourcesIngestionTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_erssan_multi_department_updates_become_one_incident_per_department(): void
    {
        CarbonImmutable::setTestNow('2026-08-19 23:00:00 America/Asuncion');
        Cache::clear();
        $this->seed([CategorySeeder::class, SourceSeeder::class, LocationSeeder::class]);
        GeocodingCache::query()->insert([
            ['query' => 'Departamento de Canindeyú', 'normalized_query' => 'departamento de canindeyu', 'latitude' => -24.25, 'longitude' => -55.25, 'precision' => LocationAccuracy::Department->value, 'provider' => 'test', 'hit_count' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['query' => 'Departamento de Concepción', 'normalized_query' => 'departamento de concepcion', 'latitude' => -23.40, 'longitude' => -57.43, 'precision' => LocationAccuracy::Department->value, 'provider' => 'test', 'hit_count' => 0, 'created_at' => now(), 'updated_at' => now()],
        ]);
        $cards = collect([12, 13, 14])->map(fn (int $day): string => "<article><h2><a href=\"https://erssan.gov.py/control-{$day}/\">Supervisión de calidad del agua en Canindeyú y Concepción</a></h2><time>Publicado el {$day} de agosto de 2026</time></article>")->implode('');
        Http::fake([
            'erssan.gov.py/noticias/' => Http::response("<main>{$cards}</main>"),
            'erssan.gov.py/control-*' => Http::response('<main><p>ERSSAN realiza la supervisión oficial en los departamentos de Canindeyú y Concepción.</p></main>'),
        ]);

        $this->artisan('alertapy:scrape erssan --force --days=7')->assertSuccessful();

        $this->assertDatabaseCount('incidents', 2);
        $this->assertDatabaseCount('incident_sources', 6);
        $this->assertDatabaseCount('incident_updates', 6);
        $this->assertSame(2, \App\Models\IncidentUpdate::query()->where('event_type', 'detected')->count());
        $this->assertSame(4, \App\Models\IncidentUpdate::query()->where('event_type', 'updated')->count());
        $this->assertDatabaseHas('incidents', ['department' => 'Canindeyú', 'city' => null, 'location_accuracy' => 'department']);
        $this->assertDatabaseHas('incidents', ['department' => 'Concepción', 'city' => null, 'location_accuracy' => 'department']);
    }
}
