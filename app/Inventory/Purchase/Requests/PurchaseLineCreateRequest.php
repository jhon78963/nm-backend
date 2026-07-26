<?php

namespace App\Inventory\Purchase\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PurchaseLineCreateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'productId' => 'required|integer|min:1',
            'sizeId' => 'required|integer|min:1',
            'barcode' => 'nullable|string|max:64',
            'purchasePrice' => 'required|numeric|min:0',
            'salePrice' => 'nullable|numeric|min:0',
            'minSalePrice' => 'nullable|numeric|min:0',
            'hasColorBreakdown' => 'required|boolean',
            'colorDeltas' => 'nullable|array',
            'colorDeltas.*.colorId' => 'required_with:colorDeltas|integer|min:1',
            'colorDeltas.*.quantity' => 'required_with:colorDeltas|integer|min:1',
            'sizeOnlyQuantity' => 'nullable|integer|min:1',
        ];
    }
}
