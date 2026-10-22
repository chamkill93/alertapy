<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class BackofficeAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_and_non_admin_cannot_open_backoffice(): void
    {
        $this->get('/admin')->assertRedirect(route('admin.login'));
        $this->getJson('/admin')->assertUnauthorized();

        $this->actingAs(User::factory()->create())->get('/admin')->assertForbidden();
    }

    public function test_admin_can_sign_in_with_normalized_email_and_sign_out(): void
    {
        $admin = User::factory()->create(['email' => 'operador@example.com', 'password' => 'Una clave segura 2026', 'is_admin' => true]);

        $this->withSession(['url.intended' => 'https://example.org/externo'])->post('/admin/login', [
            'email' => 'OPERADOR@example.com',
            'password' => 'Una clave segura 2026',
        ])->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($admin);
        $this->assertNull(session('url.intended'));

        $this->post('/admin/logout')->assertRedirect(route('admin.login'));
        $this->assertGuest();
        $this->get('/admin')->assertRedirect(route('admin.login'));
    }

    public function test_credentials_of_regular_user_do_not_allow_admin_login(): void
    {
        User::factory()->create(['email' => 'regular@example.com', 'password' => 'Una clave segura 2026']);

        $this->from('/admin/login')->post('/admin/login', [
            'email' => 'regular@example.com',
            'password' => 'Una clave segura 2026',
        ])->assertRedirect('/admin/login')->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_repeated_failed_logins_are_limited_even_with_correct_password_next(): void
    {
        User::factory()->create(['email' => 'operador@example.com', 'password' => 'Una clave segura 2026', 'is_admin' => true]);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/admin/login', ['email' => 'operador@example.com', 'password' => 'incorrecta'])->assertSessionHasErrors('email');
        }

        $this->post('/admin/login', ['email' => 'operador@example.com', 'password' => 'Una clave segura 2026'])
            ->assertStatus(429)->assertHeader('Retry-After');
        $this->assertGuest();

        $this->travel(61)->seconds();
        $this->post('/admin/login', ['email' => 'operador@example.com', 'password' => 'Una clave segura 2026'])
            ->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticated();
    }

    public function test_legacy_token_does_not_authenticate_new_backoffice(): void
    {
        config()->set('alertapy.admin_token', 'legacy-import-token');

        $this->withServerVariables(['PHP_AUTH_USER' => 'admin', 'PHP_AUTH_PW' => 'legacy-import-token'])
            ->get('/admin')->assertRedirect(route('admin.login'));
    }

    public function test_auth_pages_and_private_json_are_not_cached_or_indexed(): void
    {
        $login = $this->get('/admin/login')->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->assertTrue($login->headers->getCacheControlDirective('private'));
        $this->assertTrue($login->headers->getCacheControlDirective('no-store'));

        $admin = User::factory()->create(['is_admin' => true]);
        $json = $this->actingAs($admin)->getJson('/api/sources/status')
            ->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->assertTrue($json->headers->getCacheControlDirective('private'));
        $this->assertTrue($json->headers->getCacheControlDirective('no-store'));
    }

    public function test_admin_role_is_not_mass_assignable_from_external_fields(): void
    {
        $user = User::query()->create([
            'name' => 'Operador',
            'email' => 'regular@example.com',
            'password' => 'Una clave segura 2026',
            'is_admin' => true,
        ]);

        $this->assertFalse($user->fresh()->is_admin);
    }

    public function test_setup_is_unavailable_in_production_even_without_an_admin(): void
    {
        app()->instance('env', 'production');

        $this->get('/admin/setup')->assertNotFound();
        $this->withSession(['_token' => 'setup-token'])->post('/admin/setup', [
            '_token' => 'setup-token',
            'name' => 'Operador',
            'email' => 'operador@example.com',
            'password' => 'Una clave segura 2026',
            'password_confirmation' => 'Una clave segura 2026',
        ])->assertNotFound();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_setup_does_not_trust_client_supplied_forwarded_ip(): void
    {
        app()->instance('env', 'local');

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.12', 'HTTP_X_FORWARDED_FOR' => '127.0.0.1'])
            ->get('/admin/setup')->assertNotFound();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_setup_is_unavailable_from_non_loopback_address(): void
    {
        app()->instance('env', 'local');

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.12'])->get('/admin/setup')->assertNotFound();
        $this->withSession(['_token' => 'setup-token'])->post('/admin/setup', [
            '_token' => 'setup-token',
            'name' => 'Operador',
            'email' => 'operador@example.com',
            'password' => 'Una clave segura 2026',
            'password_confirmation' => 'Una clave segura 2026',
        ])->assertNotFound();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_local_setup_creates_only_first_admin_and_requires_csrf(): void
    {
        app()->instance('env', 'local');
        $this->get('/admin/login')->assertRedirect(route('admin.setup'));

        $payload = ['name' => 'Operador local', 'email' => 'OPERADOR@example.com', 'password' => 'Una clave segura 2026', 'password_confirmation' => 'Una clave segura 2026'];
        $this->post('/admin/setup', $payload)->assertStatus(419);
        $this->assertDatabaseCount('users', 0);

        $this->withSession(['_token' => 'setup-token'])->post('/admin/setup', [...$payload, '_token' => 'setup-token'])
            ->assertRedirect(route('admin.dashboard'));
        $admin = User::query()->sole();
        $this->assertTrue($admin->is_admin);
        $this->assertSame('operador@example.com', $admin->email);
        $this->assertTrue(Hash::check($payload['password'], $admin->password));
        $this->assertAuthenticatedAs($admin);

        $this->get('/admin/setup')->assertNotFound();
        $this->withSession(['_token' => 'setup-token'])->post('/admin/setup', [
            ...$payload, '_token' => 'setup-token', 'email' => 'otro@example.com',
        ])->assertNotFound();
        $this->assertDatabaseCount('users', 1);
    }

    public function test_local_setup_rejects_short_or_unconfirmed_passwords(): void
    {
        app()->instance('env', 'local');

        $this->withSession(['_token' => 'setup-token'])->post('/admin/setup', [
            '_token' => 'setup-token',
            'name' => 'Operador',
            'email' => 'operador@example.com',
            'password' => 'breve',
            'password_confirmation' => 'diferente',
        ])->assertSessionHasErrors('password');

        $this->assertDatabaseCount('users', 0);
    }

    public function test_only_one_local_setup_request_can_create_the_first_admin(): void
    {
        app()->instance('env', 'local');
        $lock = Cache::lock('alertapy:admin-setup', 30);
        $this->assertTrue($lock->get());

        try {
            $this->withSession(['_token' => 'setup-token'])->post('/admin/setup', [
                '_token' => 'setup-token',
                'name' => 'Operador',
                'email' => 'operador@example.com',
                'password' => 'Una clave segura 2026',
                'password_confirmation' => 'Una clave segura 2026',
            ])->assertStatus(409);
        } finally {
            $lock->release();
        }

        $this->assertDatabaseCount('users', 0);
    }

    public function test_login_and_logout_require_csrf_in_real_web_environment(): void
    {
        app()->instance('env', 'local');
        $admin = User::factory()->create(['email' => 'operador@example.com', 'password' => 'Una clave segura 2026', 'is_admin' => true]);

        $this->post('/admin/login', ['email' => $admin->email, 'password' => 'Una clave segura 2026'])->assertStatus(419);
        $this->assertGuest();

        $this->actingAs($admin)->post('/admin/logout')->assertStatus(419);
        $this->assertAuthenticatedAs($admin);
    }
}
