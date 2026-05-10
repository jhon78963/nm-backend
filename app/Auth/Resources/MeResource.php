<?php

namespace App\Auth\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Spatie\Permission\PermissionRegistrar;

class MeResource extends JsonResource
{
    /**
     * Transforma el usuario autenticado en un array plano listo para el frontend.
     *
     * Campos devueltos:
     * - Datos básicos del usuario.
     * - role / roles  : rol(es) activos en el tenant actual (scoped por Spatie Teams).
     * - permissions   : array plano de strings con TODOS los permisos efectivos del
     * usuario en su tenant (los heredados por rol + los directos).
     * Ejemplo: ["sales.create", "sales.view", "reports.export"]
     * - features      : módulos comerciales activos del tenant del usuario.
     * Ejemplo: ["electronic_billing", "ecommerce"]
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // 1. EL FIX DE ARQUITECTURA: Forzar a Spatie a mirar en el universo de este usuario
        if ($this->tenant_id) {
            app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant_id);
        }

        // 2. Ahora que Spatie tiene el contexto, extraemos los permisos
        $permissions = $this->getAllPermissions()
            ->pluck('name')
            ->sort()
            ->values()
            ->all();

        // 3. Código optimizado (Null Safe Operator de PHP 8)
        $features = $this->tenant?->features ?? [];

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
