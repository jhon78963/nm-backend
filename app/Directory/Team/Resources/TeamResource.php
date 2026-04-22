<?php

namespace App\Directory\Team\Resources;

use App\Directory\Team\Models\Team;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** * @mixin Team
 * @property int $id
 * @property string $dni
 * @property string $name
 * @property string $surname
 * @property string $salary
 * @property number $warehouse_id
 */
class TeamResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'dni' => $this->dni,
            'name' => $this->name,
            'surname' => $this->surname,
            'salary' => $this->salary,
            'warehouseId' => $this->warehouse_id,
            'userId' => $this->user_id,
            'userEmail' => $this->whenLoaded('user', fn () => $this->user->email),
        ];
    }
}
