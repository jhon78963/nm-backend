<?php

namespace App\Directory\Team\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** * @mixin Team
 * @property int $id
 * @property string $dni
 * @property string $name
 * @property string $surname
 * @property string $salary
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
        ];
    }
}
