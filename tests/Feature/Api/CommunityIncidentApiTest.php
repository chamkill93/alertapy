<?php

namespace Tests\Feature\Api;

use App\Enums\IncidentStatus;
use App\Enums\SourceType;
use App\Models\Category;
use App\Models\Incident;
use App\Models\Location;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CommunityIncidentApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_community_report_is_published_immediately_with_a_link_and_photo(): void
    {
        Storage::fake('local');
        $category = Category::query()->create([
            'name' => 'Emergencias',
            'slug' => 'emergency',
            'icon' => '🚨',
            'color' => '#F01427',
            'enabled' => true,
            'sort_order' => 1,
        ]);
        Location::query()->create([
            'type' => 'city',
            'name' => 'Asunción',
            'normalized_name' => 'asuncion',
            'city' => 'Asunción',
            'department' => 'Capital',
            'accuracy' => 'city',
            'enabled' => true,
            'latitude' => -25.2800,
            'longitude' => -57.6340,
        ]);
        $photo = UploadedFile::fake()->createWithContent(
            'evidencia.png',
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9Wl9sAAAAASUVORK5CYII=', true),
        );

        $response = $this->post('/api/incidents', [
            'category_slug' => $category->slug,
            'title' => 'Árbol caído sobre la avenida',
            'summary' => 'El árbol bloquea dos carriles y dificulta la circulación.',
            'location_text' => 'Avenida Mariscal López y Perú',
            'latitude' => -25.2850,
            'longitude' => -57.6350,
            'source_link' => 'https://example.com/reporte-ciudadano',
            'photo' => $photo,
        ], ['Accept' => 'application/json']);

        $response
            ->assertCreated()
            ->assertJsonPath('data.source.type', 'community')
            ->assertJsonPath('data.status.value', 'reported')
            ->assertJsonPath('data.location.accuracy', 'exact')
            ->assertJsonPath('data.location.city', 'Asunción');

        $incident = Incident::query()->firstOrFail();
        $this->assertSame(SourceType::Community, $incident->source_type);
        $this->assertNotNull($incident->published_at);
        $this->assertTrue($incident->expires_at->equalTo($incident->occurred_at->copy()->addHours(18)));
        $this->assertSame('unconfirmed', $incident->verification_level);
        Storage::disk('local')->assertExists($incident->community_photo_path);

        $this->getJson("/api/incidents/{$incident->public_id}")
            ->assertOk()
            ->assertJsonPath('data.community_report.link', 'https://example.com/reporte-ciudadano')
            ->assertJsonPath('data.community_report.photo_url', "/api/incidents/{$incident->public_id}/photo");

        $this->get("/api/incidents/{$incident->public_id}/photo")->assertOk();
    }

    public function test_a_report_requires_a_map_point_and_rejects_unsafe_attachments(): void
    {
        Category::query()->create([
            'name' => 'Seguridad',
            'slug' => 'security',
            'icon' => '🛡',
            'color' => '#083B73',
            'enabled' => true,
            'sort_order' => 1,
        ]);

        $this->postJson('/api/incidents', [
            'category_slug' => 'security',
            'title' => 'Reporte sin ubicación',
            'summary' => 'Este reporte no incluye las coordenadas requeridas.',
            'location_text' => 'Centro',
            'source_link' => 'javascript:alert(1)',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['latitude', 'longitude', 'source_link']);
    }

    public function test_five_negative_votes_remove_a_report_from_the_active_map_but_keep_its_history(): void
    {
        Category::query()->create([
            'name' => 'Novedades territoriales',
            'slug' => 'territorial_news',
            'icon' => '📣',
            'color' => '#009B58',
            'enabled' => true,
            'sort_order' => 1,
        ]);
        $report = $this->postJson('/api/incidents', [
            'category_slug' => 'territorial_news',
            'title' => 'Reporte sujeto a validación comunitaria',
            'summary' => 'La comunidad puede confirmar o rechazar este acontecimiento.',
            'location_text' => 'Zona de prueba',
            'latitude' => -25.2800,
            'longitude' => -57.6340,
        ])->assertCreated();
        $publicId = $report->json('data.id');

        $this->postJson("/api/incidents/{$publicId}/vote", [
            'vote' => 'positive',
            'voter_token' => 'browser-voter-token-00000001',
        ])->assertOk()->assertJsonPath('data.positive', 1);
        $this->postJson("/api/incidents/{$publicId}/vote", [
            'vote' => 'negative',
            'voter_token' => 'browser-voter-token-00000001',
        ])->assertOk()
            ->assertJsonPath('data.positive', 0)
            ->assertJsonPath('data.negative', 1);

        foreach (range(2, 4) as $number) {
            $this->postJson("/api/incidents/{$publicId}/vote", [
                'vote' => 'negative',
                'voter_token' => sprintf('browser-voter-token-%08d', $number),
            ])->assertOk()->assertJsonPath('data.removed', false);
        }

        $this->getJson('/api/incidents?period=7d')
            ->assertOk()
            ->assertJsonPath('data.0.id', $publicId);

        $this->postJson("/api/incidents/{$publicId}/vote", [
            'vote' => 'negative',
            'voter_token' => 'browser-voter-token-00000005',
        ])->assertOk()
            ->assertJsonPath('data.negative', 5)
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.removed', true)
            ->assertJsonPath('data.accepting_votes', false);

        $incident = Incident::query()->where('public_id', $publicId)->firstOrFail();
        $this->assertSame(IncidentStatus::Cancelled, $incident->status);
        $this->assertNotNull($incident->finalized_at);
        $this->assertDatabaseCount('community_incident_votes', 5);
        $this->assertDatabaseHas('incident_updates', [
            'incident_id' => $incident->id,
            'status' => 'cancelled',
            'title' => 'Reporte dado de baja por validación comunitaria',
        ]);

        $this->getJson('/api/incidents?period=7d')
            ->assertOk()
            ->assertJsonMissing(['id' => $publicId]);
        $this->getJson('/api/incidents?period=historical')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('period');
        $this->getJson("/api/incidents/{$publicId}")
            ->assertOk()
            ->assertJsonPath('data.community_validation.positive', 0)
            ->assertJsonPath('data.community_validation.negative', 5)
            ->assertJsonPath('data.community_validation.accepting_votes', false)
            ->assertJsonFragment(['event_type' => 'discarded', 'status' => 'cancelled']);
        $this->getJson('/api/incidents/updates?limit=10')
            ->assertOk()
            ->assertJsonFragment(['title' => 'Reporte dado de baja por validación comunitaria']);
    }
}
