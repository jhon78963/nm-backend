<?php

namespace App\Inventory\Product\Requests;

use App\Inventory\Product\Models\ProductSize;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InventoryReconciliationReplaceColorRequest extends FormRequest
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
        /** @var ProductSize|null $productSize */
        $productSize = $this->route('productSize');

        return [
            'fromColorId' => [
                'required',
                'integer',
                Rule::exists('product_size_color', 'color_id')->where(
                    static fn ($q) => $q->where(
                        'product_size_id',
                        $productSize instanceof ProductSize ? $productSize->id : 0,
                    ),
                ),
            ],
            'toColorId' => [
                'required',
                'integer',
                'different:fromColorId',
                Rule::exists('colors', 'id')->where(
                    static fn ($q) => $q->where('is_deleted', false),
                ),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'fromColorId' => 'color origen',
            'toColorId' => 'color destino',
        ];
    }
}
