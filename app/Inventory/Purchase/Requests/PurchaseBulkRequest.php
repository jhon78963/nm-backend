<?php

namespace App\Inventory\Purchase\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PurchaseBulkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Payload alineado con el frontend (`PurchaseBulkPayload`): camelCase.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'purchase' => 'required|array',
            'purchase.supplierName' => 'required|string|max:255',
            'purchase.vendorId' => 'nullable|integer|exists:vendors,id',
            'purchase.documentNote' => 'nullable|string|max:500',
            'purchase.registeredAt' => 'nullable|date',
            'purchase.warehouseId' => 'nullable|integer|min:1',
            'purchase.currency' => 'nullable|string|max:8',

            'catalogUpserts' => 'nullable|array',
            'catalogUpserts.products' => 'nullable|array',
            'catalogUpserts.products.*.tempId' => 'required_with:catalogUpserts.products|string',
            'catalogUpserts.products.*.name' => 'required_with:catalogUpserts.products|string|max:50',
            'catalogUpserts.products.*.genderId' => 'required_with:catalogUpserts.products|integer',

            'catalogUpserts.sizes' => 'nullable|array',
            'catalogUpserts.sizes.*.tempId' => 'required_with:catalogUpserts.sizes|string',
            'catalogUpserts.sizes.*.description' => 'required_with:catalogUpserts.sizes|string|max:25',
            'catalogUpserts.sizes.*.sizeTypeId' => 'required_with:catalogUpserts.sizes|integer',

            'catalogUpserts.colors' => 'nullable|array',
            'catalogUpserts.colors.*.tempId' => 'required_with:catalogUpserts.colors|string',
            'catalogUpserts.colors.*.description' => 'required_with:catalogUpserts.colors|string|max:25',

            'lines' => 'required|array|min:1',
            'lines.*.lineId' => 'required|string',
            'lines.*.productRef' => 'required|array',
            'lines.*.productRef.mode' => 'required|string|in:id,temp',
            'lines.*.productRef.productId' => 'nullable|integer',
            'lines.*.productRef.tempId' => 'nullable|string',
            'lines.*.sizeRef' => 'required|array',
            'lines.*.sizeRef.mode' => 'required|string|in:id,temp',
            'lines.*.sizeRef.sizeId' => 'nullable|integer',
            'lines.*.sizeRef.tempId' => 'nullable|string',
            'lines.*.colors' => 'required|array|min:1',
            'lines.*.colors.*.quantity' => 'required|numeric|min:1',
            'lines.*.colors.*.colorId' => 'nullable|integer',
            'lines.*.colors.*.tempId' => 'nullable|string|max:64',
            'lines.*.colors.*.description' => 'nullable|string|max:255',
            'lines.*.colors.*.hash' => 'nullable|string|max:64',
            'lines.*.purchasePrice' => 'nullable|numeric',
            'lines.*.salePrice' => 'nullable|numeric',
            'lines.*.minSalePrice' => 'nullable|numeric',
            'lines.*.barcode' => 'nullable|string|max:64',
            'lines.*.productSizeId' => 'nullable|integer',

            'totals' => 'nullable|array',
        ];
    }
}
