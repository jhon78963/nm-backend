<?php

namespace App\Administration\Role\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Rol Spatie (permisos RBAC).
 *
 * @mixin \Spatie\Permission\Models\Role
 */
class RoleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'permissions' => PermissionResource::collection($this->whenLoaded('permissions')),
        ];
    }
}
