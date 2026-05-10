<?php

namespace App\Administration\Tenant\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Recurso extendido para el panel System Admin.
 * Incluye conteo de usuarios, features activos y fecha de registro.
 */
class SystemAdminTenantResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'name'       => $this->name,
            'isActive'   => (bool) $this->is_active,
            'features'   => $this->features ?? [],
            'usersCount' => (int) ($this->users_count ?? 0),
            'createdAt'  => $this->created_at?->toDateString(),
        ];
    }
}
