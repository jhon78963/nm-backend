<?php

namespace App\Administration\Audit\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserActionLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'creationTime' => $this->creation_time,
            'action' => $this->action,
            'description' => $this->description,
            'metadata' => $this->metadata,
            'ipAddress' => $this->ip_address,
            'userName' => $this->whenLoaded('user', fn () => trim(($this->user->name ?? '').' '.($this->user->surname ?? ''))),
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'name' => trim(($this->user->name ?? '').' '.($this->user->surname ?? '')),
                'email' => $this->user->email,
            ]),
            'team' => $this->whenLoaded('team', fn () => $this->team ? [
                'id' => $this->team->id,
                'name' => trim(($this->team->name ?? '').' '.($this->team->surname ?? '')),
            ] : null),
            'warehouseId' => $this->warehouse_id,
        ];
    }
}
