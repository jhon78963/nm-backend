<?php

namespace App\Auth\Support;

use Illuminate\Validation\Rules\Password;

class PasswordPolicy
{
    /**
     * @return array<int, \Illuminate\Contracts\Validation\ValidationRule|string>
     */
    public static function rules(): array
    {
        return ['required', 'string', 'confirmed', Password::defaults()];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(string $label = 'contraseña'): array
    {
        return [
            'password.required' => "La {$label} es obligatoria.",
            'password.confirmed' => "La confirmación de la {$label} no coincide.",
            'password.min' => "La {$label} debe tener al menos 12 caracteres.",
            'password.mixed' => "La {$label} debe incluir mayúsculas y minúsculas.",
            'password.numbers' => "La {$label} debe incluir al menos un número.",
            'password.symbols' => "La {$label} debe incluir al menos un símbolo.",
            'password.uncompromised' => 'Esta contraseña apareció en filtraciones de datos. Elige otra.',
        ];
    }
}
