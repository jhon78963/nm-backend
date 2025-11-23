<?php

namespace App\Directory\Vendor\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** * @mixin Vendor
 * @property int $id
 * @property string $name
 * @property string $address
 * @property string $balance
 */
class VendorResource extends JsonResource
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
            'name' => $this->name,
            'address' => $this->address,
            'balance' => $this->balance,
        ];
    }
}
