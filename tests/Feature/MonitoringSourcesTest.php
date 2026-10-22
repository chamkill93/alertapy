<?php

namespace Tests\Feature;

use App\Collectors\MadesAirQualityCollector;
use App\Enums\SourceType;
use App\Models\Source;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MonitoringSourcesTest extends TestCase
{
    use RefreshDatabase;

    public function test_mades_measurements_are_idempotent_and_exposed_as_stations_not_incidents(): void
    {
        config()->set('alertapy.sources.mades-air-quality.api_key', 'test-key');
        config()->set('alertapy.sources.mades-air-quality.api_url', 'https://calidadaire.mades.gov.py/api/v1');
        $source = Source::query()->create([
            'name' => 'MADES - Calidad del Aire',
            'slug' => 'mades-air-quality',
            'type' => SourceType::Sensor,
            'base_url' => 'https://calidadaire.mades.gov.py/',
            'domain' => 'calidadaire.mades.gov.py',
            'enabled' => true,
            'polling_minutes' => 30,
        ]);
        Http::fake([
            'calidadaire.mades.gov.py/api/v1/stations' => Http::response(['data' => [[
                'id' => 7,
                'name' => 'Villa Elisa',
                'city' => 'Villa Elisa',
                'department' => 'Central',
                'latitude' => -25.36,
                'longitude' => -57.58,
                'is_active' => true,
                'is_collecting_data' => true,
            ]]]),
            'calidadaire.mades.gov.py/api/v1/stations/7/data*' => Http::response(['data' => [[
                'parameter_name' => 'PM2.5',
                'parameter_value' => 13.4,
                'unit' => 'µg/m³',
                'date_time' => now()->subHour()->toIso8601String(),
            ]]]),
        ]);

        $collector = app(MadesAirQualityCollector::class);
        $this->assertSame(1, $collector->sync($source)['measurements']);
        $this->assertSame(0, $collector->sync($source)['measurements']);

        $this->assertDatabaseCount('monitoring_stations', 1);
        $this->assertDatabaseCount('station_measurements', 1);
        $this->assertDatabaseCount('incidents', 0);
        $this->getJson('/api/monitoring/stations')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Villa Elisa')
            ->assertJsonPath('data.0.measurements.0.parameter', 'PM2.5');
    }
}
