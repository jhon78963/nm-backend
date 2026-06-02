<?php

namespace App\Auth\Requests;

use App\Auth\Support\PasswordPolicy;
use Illuminate\Foundation\Http\FormRequest;

class ChangePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('currentPassword') && ! $this->has('current_password')) {
            $this->merge(['current_password' => $this->input('currentPassword')]);
        }

        if ($this->has('passwordConfirmation') && ! $this->has('password_confirmation')) {
            $this->merge(['password_confirmation' => $this->input('passwordConfirmation')]);
        }
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $requiresCurrentPassword = ! (bool) $this->user()?->must_change_password;

        return [
            'current_password' => $requiresCurrentPassword
                ? ['required', 'string', 'current_password']
                : ['nullable'],
            'password' => PasswordPolicy::rules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return array_merge(PasswordPolicy::messages('nueva contraseña'), [
            'current_password.required' => 'La contraseña actual es obligatoria.',
            'current_password.current_password' => 'La contraseña actual no es correcta.',
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'current_password' => 'contraseña actual',
            'password' => 'nueva contraseña',
            'password_confirmation' => 'confirmación de la nueva contraseña',
        ];
    }
}
