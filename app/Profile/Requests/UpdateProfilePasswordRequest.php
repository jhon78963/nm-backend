<?php

namespace App\Profile\Requests;

use App\Auth\Support\PasswordPolicy;
use Illuminate\Foundation\Http\FormRequest;

class UpdateProfilePasswordRequest extends FormRequest
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

        if ($this->has('newPassword') && ! $this->has('password')) {
            $this->merge(['password' => $this->input('newPassword')]);
        }

        if ($this->has('newPasswordConfirmation') && ! $this->has('password_confirmation')) {
            $this->merge(['password_confirmation' => $this->input('newPasswordConfirmation')]);
        }

        if ($this->has('passwordConfirmation') && ! $this->has('password_confirmation')) {
            $this->merge(['password_confirmation' => $this->input('passwordConfirmation')]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'current_password'],
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
}
