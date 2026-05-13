<?php

namespace App\Inventory\Product\Requests;

use App\Inventory\Product\Models\Product;
use App\Inventory\Product\Models\ProductSize;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class InventoryReconciliationUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $product = $this->route('product');

        return [
            'sizes' => ['required', 'array', 'min:1'],
            'sizes.*.id' => [
                'required',
                'integer',
                Rule::exists('product_size', 'id')->where(
                    static fn ($q) => $q->where('product_id', $product instanceof Product ? $product->id : 0),
                ),
            ],
            'sizes.*.stock' => ['sometimes', 'integer', 'min:0'],
            'sizes.*.barcode' => ['sometimes', 'nullable', 'string', 'max:255'],
            'sizes.*.purchasePrice' => ['sometimes', 'nullable', 'numeric'],
            'sizes.*.salePrice' => ['sometimes', 'nullable', 'numeric'],
            'sizes.*.minSalePrice' => ['sometimes', 'nullable', 'numeric'],
            'sizes.*.colors' => ['sometimes', 'array'],
            'sizes.*.colors.*.colorId' => ['required', 'integer', 'min:1'],
            'sizes.*.colors.*.stock' => ['required', 'integer', 'min:0'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $product = $this->route('product');
            if (! $product instanceof Product) {
                return;
            }

            $sizes = $this->input('sizes', []);
            if (! is_array($sizes)) {
                return;
            }

            foreach ($sizes as $i => $sizeRow) {
                if (! is_array($sizeRow) || ! isset($sizeRow['id'])) {
                    continue;
                }

                $productSize = ProductSize::query()
                    ->where('id', (int) $sizeRow['id'])
                    ->where('product_id', $product->id)
                    ->first();

                if ($productSize === null) {
                    continue;
                }

                $colors = $sizeRow['colors'] ?? null;
                $hasColorPayload = is_array($colors) && $colors !== [];

                $hasPivots = DB::table('product_size_color')
                    ->where('product_size_id', $productSize->id)
                    ->exists();

                $hasStock = array_key_exists('stock', $sizeRow);

                if (! $hasColorPayload && ! $hasStock) {
                    $validator->errors()->add(
                        "sizes.$i",
                        'Debe indicar stock de la talla o enviar el arreglo colors con al menos un color.',
                    );
                }

                if ($hasPivots && ! $hasColorPayload) {
                    $validator->errors()->add(
                        "sizes.$i",
                        'Esta talla tiene desglose por color: debe enviar stocks en colors (no solo stock de talla).',
                    );
                }
            }
        });
    }
}
