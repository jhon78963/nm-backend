<?php

namespace App\Inventory\Product\Requests;

use App\Inventory\Product\Concerns\ValidatesAccessibleWarehouseInput;
use Illuminate\Foundation\Http\FormRequest;

class ProductCreateRequest extends FormRequest
{
    use ValidatesAccessibleWarehouseInput;

    public function authorize(): bool
    {
        return $this->authorizesRequestedWarehouse();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $warehouseRules = $this->accessibleWarehouseRule('nullable');

        return [
            'name' => 'required|string|max:50',
            'barcode' => 'nullable|string',
            'description' => 'nullable|string|max:255',
            'stock' => 'nullable|integer',
            'genderId' => 'required|integer',
            'warehouseId' => $warehouseRules,
            'warehouse_id' => $warehouseRules,
        ];
    }
}
