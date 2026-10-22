<?php

namespace Tests\Unit\Collectors;

use App\Collectors\ErssanCollector;
use App\Collectors\SenacsaCollector;
use App\Enums\SourceType;
use App\Models\Source;
use Carbon\CarbonImmutable;
use Database\Seeders\LocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OfficialHealthCollectorsTest extends TestCase
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

    public function test_erssan_keeps_a_territorial_water_control_and_rejects_an_educational_post(): void
    {
        $source = $this->source('ERSSAN', 'erssan', 'erssan.gov.py');
        Http::fake([
            'erssan.gov.py/noticias/' => Http::response('<main>
                <article><h2><a href="https://erssan.gov.py/control-calidad-luque/">Control de calidad del servicio de agua potable en Luque</a></h2><time>Publicado el 19 de agosto de 2026</time></article>
                <article><h2><a href="https://erssan.gov.py/control-dos-departamentos/">Supervisión de calidad en Canindeyú y Concepción</a></h2><time>Publicado el 19 de agosto de 2026</time></article>
                <article><h2><a href="https://erssan.gov.py/charla-lambare/">Charla de concienciación sobre el agua en Lambaré</a></h2><time>Publicado el 19 de agosto de 2026</time></article>
            </main>'),
            'erssan.gov.py/control-calidad-luque/' => Http::response('<main><h1>Control de calidad del servicio de agua potable en Luque</h1><p>La supervisión se realizó en Luque, Central.</p></main>'),
            'erssan.gov.py/control-dos-departamentos/' => Http::response('<main><h1>Supervisión de calidad en Canindeyú y Concepción</h1><p>Los controles se realizan en ambos departamentos.</p></main>'),
            'erssan.gov.py/charla-lambare/' => Http::response('<main><h1>Charla de concienciación sobre el agua en Lambaré</h1><p>Actividad educativa en Lambaré.</p></main>'),
        ]);

        $candidates = app(ErssanCollector::class)->collect($source);

        $this->assertCount(3, $candidates);
        $this->assertSame('water', $candidates[0]->metadata['category_slug']);
        $this->assertSame('Luque', $candidates[0]->metadata['city']);
        $this->assertEqualsCanonicalizing(['Central', 'Canindeyú', 'Concepción'], $candidates->pluck('metadata.department')->all());
        $this->assertTrue($candidates->skip(1)->every(fn ($candidate): bool => $candidate->metadata['city'] === null && $candidate->metadata['location_accuracy'] === 'department'));
    }

    public function test_senacsa_uses_the_separate_animal_health_category_without_personal_data(): void
    {
        $source = $this->source('SENACSA', 'senacsa', 'senacsa.gov.py');
        Http::fake([
            'senacsa.gov.py/noticias/' => Http::response('<h2><a href="https://senacsa.gov.py/alerta-sanitaria-caaguazu/">Alerta sanitaria animal en Caaguazú</a></h2>'),
            'senacsa.gov.py/alerta-sanitaria-caaguazu/' => Http::response('<main><h1>Alerta sanitaria animal en Caaguazú</h1><p>El operativo sanitario se desarrolla en Caaguazú.</p><p>19 de agosto de 2026</p></main>'),
        ]);

        $candidates = app(SenacsaCollector::class)->collect($source);

        $this->assertCount(1, $candidates);
        $this->assertSame('animal_health', $candidates[0]->metadata['category_slug']);
        $this->assertSame('Alerta sanitaria animal en Caaguazú', $candidates[0]->content);
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
