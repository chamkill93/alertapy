<?php

namespace Tests\Unit\Collectors;

use App\Collectors\MeteorologyAdvisoryCollector;
use App\Enums\SourceType;
use App\Models\Source;
use Carbon\CarbonImmutable;
use Database\Seeders\LocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MeteorologyAdvisoryCollectorTest extends TestCase
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

    public function test_it_publishes_only_a_current_geolocatable_advisory(): void
    {
        CarbonImmutable::setTestNow('2026-08-18 20:00:00 America/Asuncion');
        $source = Source::query()->create([
            'name' => 'Dirección de Meteorología e Hidrología',
            'slug' => 'meteorologia',
            'type' => SourceType::Official,
            'base_url' => 'https://www.meteorologia.gov.py/',
            'domain' => 'meteorologia.gov.py',
            'enabled' => true,
            'polling_minutes' => 5,
        ]);
        Http::fake([
            'www.meteorologia.gov.py/avisos/' => Http::response('<main>
                <h1>Aviso Meteorológico</h1><p>Fecha: 18/08/2026 Hora: 19:20</p>
                <p>Tormentas fuertes para el departamento de Boquerón.</p>
            </main>'),
        ]);

        $candidates = app(MeteorologyAdvisoryCollector::class)->collect($source);

        $this->assertCount(1, $candidates);
        $this->assertSame('weather_flood', $candidates[0]->metadata['category_slug']);
        $this->assertSame('Boquerón', $candidates[0]->metadata['department']);
        $this->assertTrue($candidates[0]->metadata['official_advisory']);
        $this->assertSame('2026-08-18T19:20:00-03:00', $candidates[0]->publishedAt->toIso8601String());
    }

    public function test_it_parses_day_before_month_in_ambiguous_dates(): void
    {
        CarbonImmutable::setTestNow('2026-10-05 20:00:00 America/Asuncion');
        Http::fake([
            'www.meteorologia.gov.py/avisos/' => Http::response('<main><p>Fecha: 05/10/2026 Hora: 19:20</p><p>Tormentas fuertes para Boquerón.</p></main>'),
        ]);

        $candidate = app(MeteorologyAdvisoryCollector::class)->collect($this->source())->sole();

        $this->assertSame('2026-10-05T19:20:00-03:00', $candidate->publishedAt->toIso8601String());
    }

    public function test_it_does_not_turn_an_undated_advisory_into_a_current_alert(): void
    {
        Http::fake([
            'www.meteorologia.gov.py/avisos/' => Http::response('<main><h1>Aviso meteorológico</h1><p>Tormentas fuertes para Boquerón.</p></main>'),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('fecha de emisión válida');
        app(MeteorologyAdvisoryCollector::class)->collect($this->source());
    }

    public function test_it_reads_the_advisory_body_and_covers_each_named_department(): void
    {
        CarbonImmutable::setTestNow('2026-10-22 14:00:00 America/Asuncion');
        Http::fake([
            'www.meteorologia.gov.py/avisos/' => Http::response('<html><head><script>var noise = "01/01/2000 08:00 Asunción";</script></head><body>
                <header>Información de Asunción</header>
                <article class="content"><div class="entry-content">
                    <script>'.str_repeat('tracking Asunción ', 300).'</script>
                    <style>.asuncion { display: none; }</style>
                    <!-- Fecha: 01/01/2000 Hora: 08:00 Asunción -->
                    <h3>Fenómeno esperado: tormentas fuertes</h3>
                    <p><strong>Fecha:</strong> 22/10/2026</p>
                    <p><strong>Hora de emisión:</strong> 13:13:12 H.O.P.</p>
                    <p><strong>Departamentos afectados:</strong> Boquerón, Itapúa.</p>
                </div></article><footer>Asunción</footer></body></html>'),
        ]);

        $candidates = app(MeteorologyAdvisoryCollector::class)->collect($this->source());

        $this->assertCount(2, $candidates);
        $this->assertSame(['Boquerón', 'Itapúa'], $candidates->pluck('metadata.department')->all());
        foreach ($candidates as $candidate) {
            $this->assertSame('2026-10-22T13:13:12-03:00', $candidate->publishedAt->toIso8601String());
            $this->assertSame('department', $candidate->metadata['location_accuracy']);
            $this->assertStringContainsString('22/10/2026', $candidate->content);
            $this->assertStringNotContainsString('tracking', $candidate->content);
            $this->assertStringNotContainsString('Asunción', $candidate->content);
        }
        $this->assertNotSame($candidates[0]->externalIdentifier, $candidates[1]->externalIdentifier);
    }

    public function test_it_rejects_invalid_calendar_dates_instead_of_normalizing_them(): void
    {
        Http::fake([
            'www.meteorologia.gov.py/avisos/' => Http::response('<main><p>Fecha: 31/02/2026 Hora: 19:20</p><p>Tormentas fuertes para Boquerón.</p></main>'),
        ]);

        $this->expectException(\RuntimeException::class);
        app(MeteorologyAdvisoryCollector::class)->collect($this->source());
    }

    public function test_it_does_not_publish_expired_or_future_advisories(): void
    {
        CarbonImmutable::setTestNow('2026-10-22 20:00:00 America/Asuncion');
        $source = $this->source();
        Http::fake([
            'www.meteorologia.gov.py/avisos/' => Http::sequence()
                ->push('<main><p>Fecha: 20/10/2026 Hora: 19:20</p><p>Tormentas fuertes para Boquerón.</p></main>')
                ->push('<main><p>Fecha: 23/10/2026 Hora: 19:20</p><p>Tormentas fuertes para Boquerón.</p></main>'),
        ]);

        $this->assertCount(0, app(MeteorologyAdvisoryCollector::class)->collect($source));
        $this->assertCount(0, app(MeteorologyAdvisoryCollector::class)->collect($source));
    }

    private function source(): Source
    {
        return Source::query()->create([
            'name' => 'Dirección de Meteorología e Hidrología',
            'slug' => 'meteorologia',
            'type' => SourceType::Official,
            'base_url' => 'https://www.meteorologia.gov.py/',
            'domain' => 'meteorologia.gov.py',
            'enabled' => true,
            'polling_minutes' => 5,
        ]);
    }

    public function test_it_returns_nothing_when_there_is_no_current_advisory(): void
    {
        $source = Source::query()->create([
            'name' => 'Dirección de Meteorología e Hidrología',
            'slug' => 'meteorologia',
            'type' => SourceType::Official,
            'base_url' => 'https://www.meteorologia.gov.py/',
            'domain' => 'meteorologia.gov.py',
            'enabled' => true,
            'polling_minutes' => 5,
        ]);
        Http::fake([
            'www.meteorologia.gov.py/avisos/' => Http::response('<h1>Detalle de Aviso Meteorológico</h1><p>No hay aviso meteorológico vigente.</p>'),
        ]);

        $this->assertCount(0, app(MeteorologyAdvisoryCollector::class)->collect($source));
    }
}
