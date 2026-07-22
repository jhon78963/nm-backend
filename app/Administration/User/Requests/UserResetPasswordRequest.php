<?php

namespace App\Administration\User\Requests;

use App\Administration\User\Models\User;
use App\Auth\Support\PasswordPolicy;
use Illuminate\Foundation\Http\FormRequest;

class UserResetPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();
        $target = $this->route('user');

        if ($actor === null || ! ($target instanceof User)) {
            return false;
        }

        return $actor->can('resetPassword', $target);
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('passwordConfirmation') && ! $this->has('password_confirmation')) {
            $this->merge(['password_confirmation' => $this->input('passwordConfirmation')]);
        }
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'password' => PasswordPolicy::rules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return PasswordPolicy::messages('nueva contraseña');
    }
}
