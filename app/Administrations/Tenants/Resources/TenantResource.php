<?php

namespace App\Administrations\Tenants\Resources;

use App\Administrations\Tenants\Resources\TenantSettingResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TenantResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'       => $this->id,
            'name'     => $this->name,
            'isActive' => $this->is_active,
            'setting'  => $this->whenLoaded('setting', fn () => new TenantSettingResource($this->setting)),
        ];
    }
}
