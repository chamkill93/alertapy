<?php

namespace Tests\Feature;

use App\Enums\SourceType;
use App\Models\Source;
use Database\Seeders\CategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MapPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_homepage_is_a_map_first_interface_without_an_empty_state_popup(): void
    {
        $this->seed(CategorySeeder::class);

        $response = $this->get('/');

        $response
            ->assertOk()
            ->assertSee('AlertaPY')
            ->assertSee('Paraguay ahora')
            ->assertSee('id="map"', false)
            ->assertSee('assets/alertapy-ui/css/alertapy-ui-kit.css', false)
            ->assertSee('id="layers-toggle"', false)
            ->assertSee('id="quick-categories"', false)
            ->assertSee('id="toggle-categories" data-quick-category="all"', false)
            ->assertSee('data-quick-category="electricity"', false)
            ->assertSee('data-quick-category="water"', false)
            ->assertSee('data-quick-category="hydrology"', false)
            ->assertSee('data-quick-category="health"', false)
            ->assertSee('>Electricidad</span>', false)
            ->assertSee('>Más capas de incidencias</span>', false)
            ->assertSee('class="layer-toggle"', false)
            ->assertSee('aria-pressed="true"', false)
            ->assertSee('>Infraestructura</h2>', false)
            ->assertSee('>Estaciones</h2>', false)
            ->assertSee('>Mapa</h2>', false)
            ->assertDontSee('id="location-search"', false)
            ->assertDontSee('id="favorites-toggle"', false)
            ->assertDontSee('id="favorites-dialog"', false)
            ->assertDontSee('id="latest-panel"', false)
            ->assertDontSee('id="latest-toggle"', false)
            ->assertDontSee('id="source-health-panel"', false)
            ->assertDontSee('data-collection-status', false)
            ->assertDontSee('data-updates-url', false)
            ->assertDontSee('data-sources-status-url', false)
            ->assertDontSee('Contexto ·')
            ->assertDontSee('>Opcional<', false)
            ->assertDontSee('Vista nacional')
            ->assertDontSee('No hay incidencias disponibles para este filtro.')
            ->assertDontSee('AlertaPY no muestra datos de demostración.')
            ->assertDontSee('id="empty-state"', false)
            ->assertDontSee('Vista técnica de marcadores')
            ->assertDontSee('@vite');

        $this->assertSame(1, substr_count($response->getContent(), 'id="toggle-categories"'), 'The primary Todas action appears once.');
    }

    public function test_public_about_dialog_describes_the_platform_without_exposing_the_connection_catalog(): void
    {
        Source::query()->create([
            'name' => 'ANDE trabajos programados',
            'slug' => 'ande-test',
            'type' => SourceType::Official,
            'base_url' => 'https://www.ande.gov.py/noticias.php?tipo_nota=trabajo_programado',
            'enabled' => true,
        ]);
        Source::query()->create([
            'name' => 'Fuente deshabilitada',
            'slug' => 'disabled-test',
            'type' => SourceType::Press,
            'base_url' => 'https://example.com/deshabilitada',
            'enabled' => false,
        ]);
        Source::query()->create([
            'name' => 'Fuente con enlace no seguro',
            'slug' => 'unsafe-test',
            'type' => SourceType::Sensor,
            'base_url' => 'javascript:alert(1)',
            'enabled' => true,
        ]);

        $response = $this->get('/');

        $response
            ->assertOk()
            ->assertSee('Acerca de AlertaPY')
            ->assertSee('enlace a la fuente original')
            ->assertDontSee('Fuentes y enlaces de referencia')
            ->assertDontSee('ANDE trabajos programados')
            ->assertDontSee('about-reference-group', false)
            ->assertDontSee('https://www.ande.gov.py/noticias.php?tipo_nota=trabajo_programado', false)
            ->assertDontSee('Fuente deshabilitada')
            ->assertDontSee('Fuente con enlace no seguro');
    }
}

