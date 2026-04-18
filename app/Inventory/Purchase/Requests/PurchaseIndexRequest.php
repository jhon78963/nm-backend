<?php

namespace App\Inventory\Purchase\Requests;

use App\Shared\Foundation\Requests\GetAllRequest;

class PurchaseIndexRequest extends GetAllRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'warehouseId' => 'nullable|integer|min:1',
            'status' => 'nullable|string|in:ACTIVE,CANCELLED',
        ]);
    }
}
