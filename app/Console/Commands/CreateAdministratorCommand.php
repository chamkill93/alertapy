<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Rules\AdministratorPassword;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class CreateAdministratorCommand extends Command
{
    protected $signature = 'alertapy:admin {email? : Correo del administrador} {--name= : Nombre del administrador}';

    protected $description = 'Crea o actualiza un administrador pidiendo la contraseña de forma oculta.';

    public function handle(): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('Ejecutá este comando en una terminal interactiva para ingresar la contraseña de forma oculta.');

            return self::FAILURE;
        }

        $email = Str::lower(trim((string) ($this->argument('email') ?: $this->ask('Correo del administrador'))));
        $existing = User::query()->where('email', $email)->first();
        $name = trim((string) ($this->option('name') ?: $this->ask('Nombre del administrador', $existing?->name)));
        $password = $this->secret('Contraseña (mínimo 12 caracteres)');
        $confirmation = $this->secret('Repetí la contraseña');

        $validator = Validator::make([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'password_confirmation' => $confirmation,
        ], [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', Password::min(12), new AdministratorPassword, 'confirmed'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $user = $existing ?? new User;
        $user->name = $name;
        $user->email = $email;
        $user->password = $password;
        $user->is_admin = true;
        $user->remember_token = null;
        $user->save();

        $this->info(($existing ? 'Administrador actualizado: ' : 'Administrador creado: ').$email);

        return self::SUCCESS;
    }
}
