<?php

namespace Tests\Unit\Collectors;

use App\Collectors\DinacCollector;
use App\Collectors\MigracionesCollector;
use App\Enums\SourceType;
use App\Models\Source;
use Carbon\CarbonImmutable;
use Database\Seeders\LocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MobilityCollectorsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(LocationSeeder::class);
        CarbonImmutable::setTestNow('2026-08-19 23:00:00 America/Asuncion');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_migraciones_classifies_only_operational_border_changes(): void
    {
        $source = $this->source('Migraciones', 'migraciones', 'migraciones.gov.py');
        Http::fake([
            'migraciones.gov.py/noticias/' => Http::response('<main>
                <article><h2><a href="https://migraciones.gov.py/nuevo-horario-integracion/">Nuevo horario para el Puente de la Integración</a></h2><time>19 de agosto de 2026</time></article>
                <article><h2><a href="https://migraciones.gov.py/cierre-amistad/">Cierre temporal del Puente de la Amistad</a></h2><time>19 de agosto de 2026</time></article>
                <article><h2><a href="https://migraciones.gov.py/controles-amistad/">Migraciones realiza controles aleatorios en el Puente de la Amistad</a></h2><time>19 de agosto de 2026</time></article>
            </main>'),
            'migraciones.gov.py/nuevo-horario-integracion/' => Http::response('<main><p>El nuevo cronograma regirá en el Puente de la Integración.</p></main>'),
            'migraciones.gov.py/cierre-amistad/' => Http::response('<main><p>El cierre afecta temporalmente al Puente de la Amistad.</p></main>'),
            'migraciones.gov.py/controles-amistad/' => Http::response('<main><p>Controles documentales regulares.</p></main>'),
        ]);

        $candidates = app(MigracionesCollector::class)->collect($source);

        $this->assertCount(2, $candidates);
        $this->assertEqualsCanonicalizing(['CLOSED', 'MODIFIED_OPERATION'], $candidates->pluck('metadata.border_status')->all());
        $this->assertEqualsCanonicalizing(['Puente Internacional de la Amistad', 'Puente Internacional de la Integración'], $candidates->pluck('metadata.location_text')->all());
    }

    public function test_dinac_rejects_a_drill_and_keeps_a_real_public_airport_disruption(): void
    {
        $source = $this->source('DINAC', 'dinac', 'dinac.gov.py');
        Http::fake([
            'www.dinac.gov.py/v3/index.php/dinac/direcciones/aeropuertos/itemlist/category/11-noticias' => Http::response('<main>
                <article><h2><a href="https://www.dinac.gov.py/cierre-silvio/">Cierre temporal del Aeropuerto Internacional Silvio Pettirossi</a></h2><time>19 de agosto de 2026</time></article>
                <article><h2><a href="https://www.dinac.gov.py/simulacro-encarnacion/">Simulacro de accidente en el Aeropuerto de Encarnación</a></h2><time>19 de agosto de 2026</time></article>
            </main>'),
            'www.dinac.gov.py/cierre-silvio/' => Http::response('<main><p>La medida afecta al Aeropuerto Internacional Silvio Pettirossi.</p></main>'),
            'www.dinac.gov.py/simulacro-encarnacion/' => Http::response('<main><p>Se trata exclusivamente de un ejercicio programado.</p></main>'),
        ]);

        $candidates = app(DinacCollector::class)->collect($source);

        $this->assertCount(1, $candidates);
        $this->assertSame('territorial_news', $candidates[0]->metadata['category_slug']);
        $this->assertSame('Luque', $candidates[0]->metadata['city']);
    }

    private function source(string $name, string $slug, string $domain): Source
    {
        return Source::query()->create([
            'name' => $name,
            'slug' => $slug,
            'type' => SourceType::Official,
            'base_url' => "https://{$domain}/",
            'domain' => $domain,
            'enabled' => true,
            'polling_minutes' => 30,
        ]);
    }
}
