<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class AdministratorPassword implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && str_contains($value, "\0")) {
            $fail('La contraseña contiene un carácter no permitido.');
        }

        // Bcrypt considers only the first 72 bytes; never accept a silently truncated credential.
        if (is_string($value) && strlen($value) > 72) {
            $fail('La contraseña es demasiado larga. Elegí una frase más corta.');
        }
    }
}
