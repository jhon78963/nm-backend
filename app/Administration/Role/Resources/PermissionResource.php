<?php

namespace App\Administration\Role\Resources;

use App\Administration\Role\Support\PermissionLabels;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PermissionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $name = (string) $this->name;

        return [
            'id' => $this->id,
            'name' => $name,
            'label' => PermissionLabels::label($name),
            'group' => PermissionLabels::group($name),
        ];
    }
}
