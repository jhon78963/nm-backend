<?php

namespace App\Inventory\Purchase\Requests;

use App\Inventory\Purchase\Models\PurchaseLine;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class PurchaseLineUpdateRequest extends FormRequest
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
            'barcode' => 'nullable|string|max:64',
            'purchasePrice' => 'required|numeric|min:0',
            'salePrice' => 'nullable|numeric|min:0',
            'minSalePrice' => 'nullable|numeric|min:0',
            'colorDeltas' => 'nullable|array',
            'colorDeltas.*.colorId' => 'required_with:colorDeltas|integer|min:1',
            'colorDeltas.*.quantity' => 'required_with:colorDeltas|integer|min:1',
            'sizeOnlyQuantity' => 'nullable|integer|min:1',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $line = $this->route('purchaseLine');
            if (! $line instanceof PurchaseLine) {
                return;
            }
            if ($line->has_color_breakdown) {
                $rows = $this->input('colorDeltas');
                if (! is_array($rows) || count($rows) < 1) {
                    $v->errors()->add('colorDeltas', 'Indica las variantes de color y sus cantidades.');
                }
            }
        });
    }
}
