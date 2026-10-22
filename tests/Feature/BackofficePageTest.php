<?php

namespace Tests\Feature;

use App\Enums\SourceType;
use App\Models\ContentCandidate;
use App\Models\RawItem;
use App\Models\Source;
use App\Models\SourceRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BackofficePageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        $this->actingAs($admin);
    }

    public function test_admin_pages_render_real_news_and_connection_history_without_exposing_secrets(): void
    {
        $source = Source::query()->create([
            'name' => 'Diario de prueba', 'slug' => 'diario-test', 'type' => SourceType::Press,
            'base_url' => 'https://diario.test/', 'domain' => 'diario.test', 'enabled' => true,
            'polling_minutes' => 15, 'last_run_at' => now(), 'last_error' => 'https://private.test?token=SECRET-CONNECTION',
        ]);
        $raw = RawItem::query()->create([
            'source_id' => $source->id, 'external_identifier' => 'water-1',
            'original_url' => 'https://diario.test/corte?token=SECRET-URL&story=1',
            'title' => 'Corte de agua en Asunción', 'raw_content' => '<p>La tubería será reparada.</p><script>alert("untrusted")</script>',
            'content_hash' => hash('sha256', 'water-1'), 'published_at' => now()->subHour(), 'detected_at' => now(),
        ]);
        ContentCandidate::query()->create([
            'source_id' => $source->id, 'raw_item_id' => $raw->id, 'source_type' => SourceType::Press,
            'status' => 'waiting_location', 'normalized_content' => 'Corte de agua en Asunción',
            'ai_reason' => 'Verificar ubicación.',
        ]);
        SourceRun::query()->create([
            'source_id' => $source->id, 'started_at' => now()->subMinute(), 'finished_at' => now(),
            'status' => 'error', 'http_status' => 503, 'discovered' => 0, 'created_candidates' => 0,
            'error' => 'password=SECRET-RUN',
        ]);

        foreach (['/admin', '/admin/news', '/admin/news/'.$raw->id, '/admin/connections', '/admin/connections/'.$source->id] as $url) {
            $this->get($url)->assertOk()->assertSee('Cerrar sesión')->assertSee('Backoffice')
                ->assertDontSee('SECRET-CONNECTION')->assertDontSee('SECRET-URL')->assertDontSee('SECRET-RUN')
                ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        }
        $this->get('/admin/news/'.$raw->id)->assertSee('La tubería será reparada.')->assertDontSee('<script>', false);
        $this->get('/admin/connections/'.$source->id)->assertSee('Consultas de los últimos 7 días')->assertSee('503');
        foreach (['/admin', '/admin/news', '/admin/news/'.$raw->id, '/admin/connections/'.$source->id] as $url) {
            $this->get($url)->assertSee('Ver publicación original')->assertSee('https://diario.test/corte', false)
                ->assertDontSee('SECRET-URL')->assertSee('rel="noopener noreferrer"', false);
        }
        Http::assertNothingSent();
    }

    public function test_empty_lists_filters_and_pagination_have_usable_states(): void
    {
        $this->get('/admin/news?q=sin-resultados')->assertOk()->assertSee('No hay noticias con estos filtros');
        $this->get('/admin/connections?q=sin-resultados')->assertOk()->assertSee('No hay conexiones con estos filtros');
        $this->get('/admin')->assertOk()->assertSee('Todavía no hay noticias')->assertSee('Sin ciclos registrados');
        $source = Source::query()->create([
            'name' => 'Fuente de prueba', 'slug' => 'paginada', 'type' => SourceType::Press,
            'base_url' => 'https://diario.test/', 'domain' => 'diario.test', 'enabled' => true, 'polling_minutes' => 15,
        ]);
        foreach (range(1, 26) as $id) {
            RawItem::query()->create([
                'source_id' => $source->id, 'external_identifier' => 'news-'.$id,
                'original_url' => 'https://diario.test/'.$id, 'title' => 'Noticia '.$id,
                'raw_content' => 'Texto', 'content_hash' => hash('sha256', 'news-'.$id),
                'published_at' => now(), 'detected_at' => now(),
            ]);
        }
        $this->get('/admin/news?source=paginada')->assertOk()->assertSee('Siguiente')->assertSee('26 resultados');
        $this->get('/admin/news?source=paginada&page=2')->assertOk()->assertSee('Noticia 1')->assertSee('Anterior');
        Http::assertNothingSent();
    }
}
