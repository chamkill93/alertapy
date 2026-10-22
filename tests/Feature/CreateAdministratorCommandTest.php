<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateAdministratorCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_creates_admin_with_hashed_password(): void
    {
        $this->artisan('alertapy:admin', ['email' => 'OPERADOR@example.com', '--name' => 'Operador'])
            ->expectsQuestion('Contraseña (mínimo 12 caracteres)', 'Una clave segura 2026')
            ->expectsQuestion('Repetí la contraseña', 'Una clave segura 2026')
            ->expectsOutput('Administrador creado: operador@example.com')
            ->assertSuccessful();

        $admin = User::query()->sole();
        $this->assertTrue($admin->is_admin);
        $this->assertSame('Operador', $admin->name);
        $this->assertNotSame('Una clave segura 2026', $admin->password);
        $this->assertTrue(Hash::check('Una clave segura 2026', $admin->password));
    }

    public function test_command_updates_existing_admin_without_duplicate_user(): void
    {
        $user = User::factory()->create(['email' => 'operador@example.com', 'password' => 'Contraseña anterior 2026']);

        $this->artisan('alertapy:admin', ['email' => $user->email, '--name' => 'Nuevo nombre'])
            ->expectsQuestion('Contraseña (mínimo 12 caracteres)', 'Contraseña renovada 2026')
            ->expectsQuestion('Repetí la contraseña', 'Contraseña renovada 2026')
            ->expectsOutput('Administrador actualizado: operador@example.com')
            ->assertSuccessful();

        $this->assertDatabaseCount('users', 1);
        $this->assertTrue($user->fresh()->is_admin);
        $this->assertSame('Nuevo nombre', $user->fresh()->name);
        $this->assertTrue(Hash::check('Contraseña renovada 2026', $user->fresh()->password));
    }

    public function test_invalid_confirmation_does_not_modify_existing_user(): void
    {
        $user = User::factory()->create(['email' => 'operador@example.com']);
        $oldHash = $user->password;

        $this->artisan('alertapy:admin', ['email' => $user->email, '--name' => 'Operador'])
            ->expectsQuestion('Contraseña (mínimo 12 caracteres)', 'Una clave segura 2026')
            ->expectsQuestion('Repetí la contraseña', 'Una clave distinta 2026')
            ->assertFailed();

        $this->assertFalse($user->fresh()->is_admin);
        $this->assertSame($oldHash, $user->fresh()->password);
    }

    public function test_command_refuses_non_interactive_credentials(): void
    {
        $this->artisan('alertapy:admin', ['email' => 'operador@example.com', '--name' => 'Operador', '--no-interaction' => true])
            ->expectsOutput('Ejecutá este comando en una terminal interactiva para ingresar la contraseña de forma oculta.')
            ->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_command_rejects_password_that_bcrypt_would_truncate(): void
    {
        $longPassword = str_repeat('é', 37);
        $this->artisan('alertapy:admin', ['email' => 'operador@example.com', '--name' => 'Operador'])
            ->expectsQuestion('Contraseña (mínimo 12 caracteres)', $longPassword)
            ->expectsQuestion('Repetí la contraseña', $longPassword)
            ->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }
}
