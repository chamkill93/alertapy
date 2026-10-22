<?php

namespace Tests\Feature\Api;

use App\Enums\IncidentEventType;
use App\Enums\IncidentStatus;
use App\Enums\LocationAccuracy;
use App\Enums\Severity;
use App\Enums\SourceType;
use App\Models\Category;
use App\Models\Incident;
use App\Models\IncidentSource;
use App\Models\IncidentUpdate;
use App\Models\RawItem;
use App\Models\Source;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IncidentApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_database_returns_an_empty_real_dataset(): void
    {
        $this->getJson('/api/incidents')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonCount(0, 'summary');
    }

    public function test_it_returns_only_published_current_incidents_by_default(): void
    {
        $published = $this->createIncident();
        $this->createIncident(['published_at' => null, 'title' => 'No publicado']);
        $this->createIncident([
            'title' => 'Vencido',
            'expires_at' => now()->subMinute(),
        ]);

        $response = $this->getJson('/api/incidents');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $published->public_id)
            ->assertJsonPath('summary.0.count', 1);
    }

    public function test_it_filters_by_category_city_period_and_bounding_box(): void
    {
        $inside = $this->createIncident([
            'city' => 'Asunción',
            'location_text' => 'Asunción',
            'latitude' => -25.30,
            'longitude' => -57.60,
        ]);
        $this->createIncident([
            'city' => 'Encarnación',
            'location_text' => 'Encarnación',
            'latitude' => -27.33,
            'longitude' => -55.86,
        ]);

        $response = $this->getJson('/api/incidents?period=24h&categories=electricity&city=Asunci%C3%B3n&bbox=-26,-59,-24,-56');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $inside->public_id);
    }

    public function test_invalid_bounding_box_is_rejected(): void
    {
        $this->getJson('/api/incidents?bbox=not-a-box')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('bbox');
    }

    public function test_pagination_preserves_filters_and_orders_equal_dates_consistently(): void
    {
        $publishedAt = now()->subHour();
        $first = $this->createIncident(['published_at' => $publishedAt]);
        $second = $this->createIncident(['published_at' => $publishedAt]);
        $third = $this->createIncident(['published_at' => $publishedAt]);

        $page = $this->getJson('/api/incidents?period=7d&city=Asunci%C3%B3n&per_page=2')
            ->assertOk()
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('data.0.id', $third->public_id)
            ->assertJsonPath('data.1.id', $second->public_id);

        $next = $page->json('links.next');
        $this->assertStringContainsString('period=7d', $next);
        $this->assertStringContainsString('city=Asunci', $next);
        $this->getJson($next)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $first->public_id)
            ->assertJsonPath('links.next', null);
    }

    public function test_map_cursor_does_not_skip_incidents_when_an_earlier_result_expires(): void
    {
        $publishedAt = now()->subHour();
        $first = $this->createIncident(['published_at' => $publishedAt]);
        $second = $this->createIncident(['published_at' => $publishedAt]);
        $third = $this->createIncident(['published_at' => $publishedAt]);
        $fourth = $this->createIncident(['published_at' => $publishedAt]);

        $page = $this->getJson('/api/incidents?period=now&pagination=cursor&per_page=2')
            ->assertOk()
            ->assertJsonPath('data.0.id', $fourth->public_id)
            ->assertJsonPath('data.1.id', $third->public_id);
        $fourth->update(['expires_at' => now()->subMinute()]);

        $this->getJson($page->json('links.next'))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $second->public_id)
            ->assertJsonPath('data.1.id', $first->public_id)
            ->assertJsonPath('links.next', null);
    }

    public function test_detail_preserves_original_sources_and_hides_raw_evidence(): void
    {
        $incident = $this->createIncident();
        $source = $this->createSource();
        $rawItem = RawItem::query()->create([
            'source_id' => $source->id,
            'external_identifier' => 'source-1',
            'original_url' => 'https://example.com.py/noticia-real',
            'title' => 'Título original',
            'raw_content' => '<p>Evidencia cruda que no debe exponerse</p>',
            'content_hash' => hash('sha256', 'evidencia'),
            'published_at' => now()->subMinutes(20),
            'detected_at' => now()->subMinutes(10),
        ]);
        IncidentSource::query()->create([
            'incident_id' => $incident->id,
            'source_id' => $source->id,
            'raw_item_id' => $rawItem->id,
            'original_title' => 'Título original',
            'original_url' => 'https://example.com.py/noticia-real',
            'published_at' => now()->subMinutes(20),
            'detected_at' => now()->subMinutes(10),
        ]);
        IncidentUpdate::query()->create([
            'incident_id' => $incident->id,
            'source_id' => $source->id,
            'raw_item_id' => $rawItem->id,
            'title' => 'Actualización verificable',
            'summary' => 'La fuente añadió información.',
            'status' => IncidentStatus::Active,
            'occurred_at' => now()->subMinutes(5),
        ]);

        $response = $this->getJson("/api/incidents/{$incident->public_id}");

        $response
            ->assertOk()
            ->assertJsonPath('data.sources.0.original_title', 'Título original')
            ->assertJsonPath('data.sources.0.original_url', 'https://example.com.py/noticia-real')
            ->assertJsonPath('data.updates.0.title', 'Actualización verificable')
            ->assertJsonMissing(['raw_content' => '<p>Evidencia cruda que no debe exponerse</p>']);
    }

    public function test_unpublished_incident_detail_is_not_publicly_accessible(): void
    {
        $incident = $this->createIncident(['published_at' => null]);

        $this->getJson("/api/incidents/{$incident->public_id}")->assertNotFound();
    }

    public function test_latest_activity_includes_new_incidents_and_keeps_only_the_newest_item_per_incident(): void
    {
        $incident = $this->createIncident();
        $source = $this->createSource();
        IncidentSource::query()->create([
            'incident_id' => $incident->id,
            'source_id' => $source->id,
            'original_title' => $incident->title,
            'original_url' => 'https://example.com.py/incidencia-verificable',
            'published_at' => $incident->published_at,
            'detected_at' => now(),
        ]);

        $this->getJson('/api/incidents/updates?limit=10')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.activity_type', 'new')
            ->assertJsonPath('data.0.incident_id', $incident->public_id)
            ->assertJsonPath('data.0.category.slug', 'electricity')
            ->assertJsonPath('data.0.location.text', 'Asunción')
            ->assertJsonPath('data.0.source.label', 'Oficial')
            ->assertJsonPath('data.0.source.url', 'https://example.com.py/incidencia-verificable');

        IncidentUpdate::query()->create([
            'incident_id' => $incident->id,
            'source_id' => $source->id,
            'title' => 'Servicio restablecido',
            'summary' => 'La fuente confirmó la normalización.',
            'status' => IncidentStatus::Finalized,
            'occurred_at' => now(),
        ]);

        $this->getJson('/api/incidents/updates?limit=10')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.activity_type', 'update')
            ->assertJsonPath('data.0.title', 'Servicio restablecido')
            ->assertJsonPath('data.0.source.name', 'Medio de prueba');
    }

    public function test_detection_activity_uses_the_incident_headline_and_keeps_later_closure_titles(): void
    {
        $this->freezeTime();
        $incident = $this->createIncident([
            'title' => 'Corte programado de energía en Asunción',
            'summary' => 'La fuente informa tareas de mantenimiento de 08:00 a 10:00.',
        ]);
        $source = $this->createSource();
        $detected = IncidentUpdate::query()->create([
            'incident_id' => $incident->id,
            'source_id' => $source->id,
            'title' => 'Incidencia detectada',
            'summary' => 'Registro técnico de la detección.',
            'status' => $incident->status,
            'event_type' => IncidentEventType::Detected,
            'occurred_at' => now(),
        ]);

        $this->assertTrue($incident->created_at->equalTo($detected->created_at));
        $this->getJson('/api/incidents/updates?limit=10')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.activity_type', 'new')
            ->assertJsonPath('data.0.title', $incident->title)
            ->assertJsonPath('data.0.summary', $incident->summary);
        $this->getJson("/api/incidents/{$incident->public_id}")
            ->assertOk()
            ->assertJsonPath('data.updates.0.title', 'Incidencia detectada')
            ->assertJsonPath('data.updates.0.event_type', 'detected');

        $this->travel(5)->minutes();
        IncidentUpdate::query()->create([
            'incident_id' => $incident->id,
            'source_id' => $source->id,
            'title' => 'Servicio restablecido en Asunción',
            'summary' => 'La fuente confirmó que finalizaron las tareas.',
            'status' => IncidentStatus::Finalized,
            'event_type' => IncidentEventType::ServiceRestored,
            'occurred_at' => now(),
        ]);

        $this->getJson('/api/incidents/updates?limit=10')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.activity_type', 'update')
            ->assertJsonPath('data.0.title', 'Servicio restablecido en Asunción')
            ->assertJsonPath('data.0.summary', 'La fuente confirmó que finalizaron las tareas.');
        $this->assertSame('Incidencia detectada', $detected->fresh()->title);
        $this->travelBack();
    }

    public function test_bulk_technical_expirations_do_not_displace_news_or_real_closures(): void
    {
        $this->freezeTime();
        $news = $this->createIncident(['title' => 'Nuevo corte de energía reportado hoy']);
        $closed = $this->createIncident(['title' => 'Interrupción de energía en el centro']);
        IncidentUpdate::query()->create([
            'incident_id' => $closed->id,
            'title' => 'Servicio restablecido por la cuadrilla',
            'summary' => 'La fuente confirmó el restablecimiento del servicio.',
            'status' => IncidentStatus::Finalized,
            'event_type' => IncidentEventType::ServiceRestored,
            'occurred_at' => now(),
        ]);
        $this->travel(5)->minutes();
        $expiredIncident = null;
        for ($index = 0; $index < 105; $index++) {
            $expiredIncident = $this->createIncident([
                'title' => "Incidencia antigua {$index}",
                'status' => IncidentStatus::Finalized,
                'published_at' => now()->subDays(2),
                'expires_at' => now()->subDay(),
            ]);
            $expiredIncident->forceFill(['created_at' => now()->subDays(2), 'updated_at' => now()->subDays(2)])->save();
            IncidentUpdate::query()->create([
                'incident_id' => $expiredIncident->id,
                'title' => 'Vigencia finalizada',
                'summary' => 'Venció según la política temporal de su categoría.',
                'status' => IncidentStatus::Finalized,
                'event_type' => IncidentEventType::Expired,
                'occurred_at' => $expiredIncident->expires_at,
            ]);
        }

        $this->getJson('/api/incidents/updates?limit=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.incident_id', $closed->public_id)
            ->assertJsonPath('data.0.activity_type', 'update')
            ->assertJsonPath('data.0.title', 'Servicio restablecido por la cuadrilla')
            ->assertJsonPath('data.1.incident_id', $news->public_id)
            ->assertJsonPath('data.1.activity_type', 'new')
            ->assertJsonPath('data.1.title', $news->title);
        $this->getJson("/api/incidents/{$expiredIncident->public_id}")
            ->assertOk()
            ->assertJsonPath('data.updates.0.title', 'Vigencia finalizada')
            ->assertJsonPath('data.updates.0.event_type', 'expired');
        $this->assertDatabaseCount('incident_updates', 106);
        $this->travelBack();
    }

    public function test_latest_activity_distinguishes_expired_validity_from_confirmed_resolution(): void
    {
        $expired = $this->createIncident([
            'title' => 'Aviso cuya vigencia terminó',
            'status' => IncidentStatus::Finalized,
            'published_at' => now()->subDays(2),
            'expires_at' => now()->subDay(),
            'expired_at' => now()->subDay(),
        ]);
        IncidentUpdate::query()->create([
            'incident_id' => $expired->id,
            'title' => 'La fuente amplió los detalles del aviso',
            'summary' => 'Actualización de la evidencia original.',
            'event_type' => IncidentEventType::Updated,
            'occurred_at' => now()->subDays(2),
        ]);
        $resolved = $this->createIncident([
            'title' => 'Interrupción con restablecimiento confirmado',
            'status' => IncidentStatus::Finalized,
            'resolved_at' => now(),
        ]);
        IncidentUpdate::query()->create([
            'incident_id' => $resolved->id,
            'title' => 'Servicio restablecido por la cuadrilla',
            'summary' => 'La fuente confirmó la normalización.',
            'event_type' => IncidentEventType::ServiceRestored,
            'occurred_at' => now(),
        ]);

        $response = $this->getJson('/api/incidents/updates?limit=10')->assertOk()->assertJsonCount(2, 'data');
        $activity = collect($response->json('data'))->keyBy('incident_id');
        $this->assertSame(['value' => 'expired', 'label' => 'Vencido'], $activity[$expired->public_id]['lifecycle_status']);
        $this->assertSame('La fuente amplió los detalles del aviso', $activity[$expired->public_id]['title']);
        $this->assertSame(['value' => 'resolved', 'label' => 'Resuelto'], $activity[$resolved->public_id]['lifecycle_status']);
        $this->assertSame('Servicio restablecido por la cuadrilla', $activity[$resolved->public_id]['title']);
    }

    public function test_latest_activity_limits_ande_items_to_keep_the_feed_diverse(): void
    {
        $ande = Source::query()->create([
            'name' => 'ANDE',
            'slug' => 'ande',
            'type' => SourceType::Official,
            'base_url' => 'https://www.ande.gov.py/',
            'domain' => 'ande.gov.py',
            'enabled' => true,
            'polling_minutes' => 5,
        ]);

        foreach (range(1, 5) as $number) {
            $incident = $this->createIncident([
                'title' => "Trabajo programado ANDE {$number}",
                'published_at' => now()->subMinutes($number),
            ]);
            $incident->forceFill(['created_at' => now()->subSeconds($number)])->save();
            $rawItem = RawItem::query()->create([
                'source_id' => $ande->id,
                'external_identifier' => "ande-{$number}",
                'original_url' => "https://www.ande.gov.py/trabajo-{$number}",
                'title' => $incident->title,
                'raw_content' => $incident->summary,
                'content_hash' => hash('sha256', "ande-{$number}"),
                'published_at' => $incident->published_at,
                'detected_at' => now(),
            ]);
            IncidentSource::query()->create([
                'incident_id' => $incident->id,
                'source_id' => $ande->id,
                'raw_item_id' => $rawItem->id,
                'original_title' => $incident->title,
                'original_url' => $rawItem->original_url,
                'published_at' => $incident->published_at,
                'detected_at' => now(),
            ]);
        }

        $response = $this->getJson('/api/incidents/updates?limit=10')->assertOk();
        $andeItems = collect($response->json('data'))->where('source.name', 'ANDE');

        $this->assertCount(2, $andeItems);
    }

    private function createIncident(array $overrides = []): Incident
    {
        $category = Category::query()->firstOrCreate(
            ['slug' => 'electricity'],
            [
                'name' => 'Electricidad',
                'icon' => '⚡',
                'color' => '#F6B73C',
                'enabled' => true,
                'sort_order' => 1,
            ],
        );

        return Incident::query()->create(array_merge([
            'category_id' => $category->id,
            'title' => 'Incidencia verificable de prueba',
            'summary' => 'Dato simulado únicamente dentro de PHPUnit.',
            'source_type' => SourceType::Official,
            'verification_level' => 'official',
            'status' => IncidentStatus::Active,
            'severity' => Severity::Medium,
            'latitude' => -25.30,
            'longitude' => -57.60,
            'location_accuracy' => LocationAccuracy::City,
            'department' => 'Capital',
            'city' => 'Asunción',
            'location_text' => 'Asunción',
            'starts_at' => now()->subMinutes(15),
            'expires_at' => now()->addHour(),
            'published_at' => now()->subMinutes(10),
        ], $overrides));
    }

    private function createSource(): Source
    {
        return Source::query()->create([
            'name' => 'Medio de prueba',
            'slug' => 'medio-prueba',
            'type' => SourceType::Press,
            'base_url' => 'https://example.com.py/',
            'domain' => 'example.com.py',
            'enabled' => true,
            'polling_minutes' => 5,
        ]);
    }
}
