<?php

namespace App\Directory\Customer\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** * @mixin Customer
 * @property int $id
 * @property string $dni
 * @property string $name
 * @property string $surname
 */
class CustomerResource extends JsonResource
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
        ];
    }
}
