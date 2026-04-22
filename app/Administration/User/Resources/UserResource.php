<?php

namespace App\Administration\User\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'username' => $this->username,
            'email' => $this->email,
            'name' => $this->name,
            'surname' => $this->surname,
            'profilePicture' => $this->profile_picture,
            'roles' => $this->getRoleNames()->values()->all(),
            'role' => $this->getRoleNames()->first(),
            'tenantId' => $this->tenant_id,
            'warehouseId' => $this->warehouse_id,
        ];
    }
}
