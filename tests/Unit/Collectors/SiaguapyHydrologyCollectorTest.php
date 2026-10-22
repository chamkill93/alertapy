<?php

namespace Tests\Unit\Collectors;

use App\Collectors\SiaguapyHydrologyCollector;
use App\Enums\SourceType;
use App\Models\Source;
use Carbon\CarbonImmutable;
use Database\Seeders\LocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class SiaguapyHydrologyCollectorTest extends TestCase
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

    public function test_it_keeps_only_recent_rows_with_an_alert_state(): void
    {
        CarbonImmutable::setTestNow('2026-08-18 15:00:00 America/Asuncion');
        $source = Source::query()->create([
            'name' => 'SIAGUAPY',
            'slug' => 'siaguapy',
            'type' => SourceType::Official,
            'base_url' => 'https://siaguapy.mades.gov.py/',
            'domain' => 'siaguapy.mades.gov.py',
            'enabled' => true,
            'polling_minutes' => 30,
        ]);
        Http::fake([
            'siaguapy.mades.gov.py/alertas-hidrologicas' => Http::response('<table><tbody>
                <tr><td>Fuerte Olimpo</td><td>18/08/26</td><td>5.20 mts</td><td>Alerta</td></tr>
                <tr><td>Asunción</td><td>18/08/26</td><td>1.77 mts</td><td>Normal</td></tr>
            </tbody></table>'),
        ]);

        $candidates = app(SiaguapyHydrologyCollector::class)->collect($source);

        $this->assertCount(1, $candidates);
        $this->assertSame('hydrology', $candidates[0]->metadata['category_slug']);
        $this->assertSame('Fuerte Olimpo', $candidates[0]->metadata['city']);
        $this->assertTrue($candidates[0]->metadata['official_hydrology_alert']);
    }

    public function test_it_includes_disaster_and_pumping_restrictions_but_never_normal_readings(): void
    {
        CarbonImmutable::setTestNow('2026-10-22 15:00:00 America/Asuncion');
        $source = new Source(['slug' => 'siaguapy', 'type' => SourceType::Sensor]);
        Http::fake(['siaguapy.mades.gov.py/alertas-hidrologicas' => Http::response('<table>
            <tr><td>Pilar</td><td>22/10/26</td><td>9.20 mts</td><td>Desastre</td></tr>
            <tr><td>Villa Florida</td><td>22/10/2026</td><td>1.10 mts</td><td>10 m3/s</td><td>Cese de bombeo</td></tr>
            <tr><td>Yuty</td><td>2026-10-22</td><td>1.20 mts</td><td>20 m3/s</td><td>Bombeo escalonado</td></tr>
            <tr><td>Asunción</td><td>22/10/26</td><td>2.83 mts</td><td>Normal</td></tr>
            <tr><td>Villa Florida</td><td>22/10/26</td><td>4.25 mts</td><td>649.02 m3/s</td><td>Sin Inconvenientes</td></tr>
        </table>')]);

        $candidates = app(SiaguapyHydrologyCollector::class)->collect($source);

        $this->assertCount(3, $candidates);
        $this->assertSame(['Pilar', 'Villa Florida', 'Yuty'], $candidates->pluck('metadata.location_text')->all());
        $this->assertSame('Villa Florida, Paraguay', $candidates[1]->detectedLocation);
        $this->assertNull($candidates[1]->latitude, 'An unknown station must not receive invented coordinates.');
        $this->assertSame(['hydrology'], $candidates->pluck('metadata.category_slug')->unique()->values()->all());
    }

    public function test_it_rejects_missing_invalid_expired_and_future_observation_dates(): void
    {
        CarbonImmutable::setTestNow('2026-10-22 15:00:00 America/Asuncion');
        $source = new Source(['slug' => 'siaguapy', 'type' => SourceType::Sensor]);
        Http::fake(['siaguapy.mades.gov.py/alertas-hidrologicas' => Http::response('<table>
            <tr><td>Pilar</td><td></td><td>9.20 mts</td><td>Alerta</td></tr>
            <tr><td>Pilar</td><td>31/09/26</td><td>9.20 mts</td><td>Alerta</td></tr>
            <tr><td>Pilar</td><td>22/08/26</td><td>9.20 mts</td><td>Alerta</td></tr>
            <tr><td>Pilar</td><td>23/10/26</td><td>9.20 mts</td><td>Alerta</td></tr>
        </table>')]);

        $this->assertCount(0, app(SiaguapyHydrologyCollector::class)->collect($source));
    }

    public function test_negated_or_unknown_state_labels_do_not_become_alerts(): void
    {
        CarbonImmutable::setTestNow('2026-10-22 15:00:00 America/Asuncion');
        $rows = collect(['Normal', 'Sin alerta', 'Sin peligro', 'Sin precaución', 'Sin inconvenientes', 'No hay alerta', 'Alerta cancelada', 'Estado desconocido'])
            ->map(fn (string $state): string => '<tr><td>Pilar</td><td>22/10/26</td><td>6.10 mts</td><td>'.$state.'</td></tr>')
            ->implode('');
        Http::fake(['siaguapy.mades.gov.py/alertas-hidrologicas' => Http::response('<table>'.$rows.'</table>')]);

        $this->assertCount(0, app(SiaguapyHydrologyCollector::class)->collect(new Source(['slug' => 'siaguapy', 'type' => SourceType::Sensor])));
    }

    public function test_missing_station_table_is_an_error_instead_of_a_false_success_with_zero_alerts(): void
    {
        Http::fake(['siaguapy.mades.gov.py/alertas-hidrologicas' => Http::response('<html>Página temporalmente no disponible</html>')]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('tabla de estaciones');

        app(SiaguapyHydrologyCollector::class)->collect(new Source(['slug' => 'siaguapy', 'type' => SourceType::Sensor]));
    }
}
