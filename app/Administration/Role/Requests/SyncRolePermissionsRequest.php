<?php

namespace App\Administration\Role\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

class SyncRolePermissionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $role = $this->route('role');

        return $user !== null
            && $role instanceof Role
            && $user->can('syncPermissions', $role);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'permissions' => ['required', 'array'],
            'permissions.*' => [
                'string',
                'max:255',
                Rule::exists('permissions', 'name')->where('guard_name', 'web'),
            ],
        ];
    }
}
