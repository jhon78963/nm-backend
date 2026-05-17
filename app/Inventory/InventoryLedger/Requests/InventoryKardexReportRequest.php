<?php

namespace App\Inventory\InventoryLedger\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InventoryKardexReportRequest extends FormRequest
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
        $tenantId = $this->user()?->tenant_id;

        return [
            'warehouse_id' => [
                'required',
                'integer',
                Rule::exists('warehouses', 'id')->where(
                    static function ($query) use ($tenantId): void {
                        if ($tenantId !== null) {
                            $query->where('tenant_id', (int) $tenantId);
                        }
                    },
                ),
            ],
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'product_size_id' => [
                'required',
                'integer',
                Rule::exists('product_size', 'id')->where(
                    'product_id',
                    (int) $this->input('product_id'),
                ),
            ],
            'color_id' => ['sometimes', 'nullable', 'integer', 'exists:colors,id'],
            'fecha_inicio' => ['required', 'date'],
            'fecha_fin' => ['required', 'date', 'after_or_equal:fecha_inicio'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('color_id')) {
            $this->merge(['color_id' => null]);

            return;
        }

        if ($this->input('color_id') === '') {
            $this->merge(['color_id' => null]);
        }
    }
}
