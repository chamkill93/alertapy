<?php

namespace Tests\Feature\Api;

use App\Enums\SourceType;
use App\Models\Source;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SourceStatusApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_status_does_not_expose_internal_errors_or_conditional_headers(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        Source::query()->create([
            'name' => 'Fuente oficial',
            'slug' => 'fuente-oficial',
            'type' => SourceType::Official,
            'base_url' => 'https://example.gov.py/',
            'domain' => 'example.gov.py',
            'enabled' => true,
            'polling_minutes' => 10,
            'last_run_at' => now(),
            'last_error' => 'Detalle interno sensible',
            'etag' => 'valor-interno',
        ]);

        $response = $this->getJson('/api/sources/status');

        $response
            ->assertOk()
            ->assertJsonPath('data.0.status', 'error')
            ->assertJsonPath('data.0.health_reason', 'SOURCE_REQUEST_FAILED')
            ->assertJsonPath('meta.scheduler_status', 'MISSING')
            ->assertDontSee('Detalle interno sensible')
            ->assertJsonMissing(['last_error' => 'Detalle interno sensible'])
            ->assertJsonMissing(['etag' => 'valor-interno']);
    }

    public function test_connection_status_is_not_available_to_anonymous_visitors(): void
    {
        Source::query()->create([
            'name' => 'Conexión privada', 'slug' => 'conexion-privada', 'type' => SourceType::Official,
            'base_url' => 'https://private.test/', 'domain' => 'private.test', 'enabled' => true,
            'polling_minutes' => 15, 'last_error' => 'private-internal-error',
        ]);

        $this->getJson('/api/sources/status')->assertUnauthorized()->assertDontSee('Conexión privada')->assertDontSee('private-internal-error');
        $this->getJson('/api/sources')->assertUnauthorized()->assertDontSee('Conexión privada');
    }
}
