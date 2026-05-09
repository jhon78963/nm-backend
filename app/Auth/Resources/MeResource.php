<?php

namespace App\Auth\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MeResource extends JsonResource
{
    /**
     * Transforma el usuario autenticado en un array plano listo para el frontend.
     *
     * Campos devueltos:
     *  - Datos básicos del usuario.
     *  - role / roles  : rol(es) activos en el tenant actual (scoped por Spatie Teams).
     *  - permissions   : array plano de strings con TODOS los permisos efectivos del
     *                    usuario en su tenant (los heredados por rol + los directos).
     *                    Ejemplo: ["sales.create", "sales.view", "reports.export"]
     *  - features      : módulos comerciales activos del tenant del usuario.
     *                    Ejemplo: ["electronic_billing", "ecommerce"]
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // getAllPermissions() respeta el team context (tenant_id) gracias al TenantTeamResolver.
        $permissions = $this->getAllPermissions()
            ->pluck('name')
            ->sort()
            ->values()
            ->all();

        $features = $this->relationLoaded('tenant')
            ? ($this->tenant?->features ?? [])
            : ($this->tenant?->features ?? []);

        return [
            'username'       => $this->username,
            'email'          => $this->email,
            'name'           => $this->name,
            'surname'        => $this->surname,
            'profilePicture' => $this->profile_picture,
            'tenantId'       => $this->tenant_id,
            'warehouseId'    => $this->warehouse_id,

            // Roles del usuario en su tenant actual.
            'role'           => $this->getRoleNames()->first(),
            'roles'          => $this->getRoleNames()->values()->all(),

            // Array plano de todos los permisos efectivos (rol + directos) en el tenant.
            'permissions'    => $permissions,

            // Módulos comerciales activos del tenant (Feature Toggling).
            'features'       => $features,
        ];
    }
}
