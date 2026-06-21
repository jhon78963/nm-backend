<?php

namespace App\Finance\Sale\Requests;

use App\Finance\Sale\Models\Sale;
use App\Finance\Sale\Models\SaleDetail;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SaleUpdateRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'creationTime' => 'nullable',
            'items' => 'nullable|array|min:1',
            'items.*.id' => 'nullable|integer|exists:sale_details,id',
            'items.*.unit_price' => 'required|decimal:0,2|min:0',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.product_size_id' => 'nullable|integer|exists:product_size,id',
            'items.*.color_id' => 'nullable|integer',
            'payments' => 'nullable|array',
            'payments.*.method' => 'required|string',
            'payments.*.amount' => 'required|decimal:0,2|min:0',
            'payments.*.reference' => 'nullable|string',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $items = $this->input('items', []);
            if (! is_array($items)) {
                return;
            }

            /** @var Sale|null $sale */
            $sale = $this->route('sale');

            foreach ($items as $index => $item) {
                if (! is_array($item)) {
                    continue;
                }

                $detailId = $item['id'] ?? null;
                $productSizeId = $item['product_size_id'] ?? null;

                if (empty($detailId) && empty($productSizeId)) {
                    $validator->errors()->add(
                        "items.{$index}.product_size_id",
                        'Debe seleccionar un producto para los ítems nuevos.',
                    );
                }

                if (! empty($detailId) && $sale instanceof Sale) {
                    $belongsToSale = SaleDetail::query()
                        ->whereKey((int) $detailId)
                        ->where('sale_id', $sale->id)
                        ->exists();

                    if (! $belongsToSale) {
                        $validator->errors()->add(
                            "items.{$index}.id",
                            'El ítem no pertenece a esta venta.',
                        );
                    }
                }
            }
        });
    }
}
