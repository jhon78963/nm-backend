<?php

namespace App\Inventory\InventoryLedger\Requests;

use App\Inventory\InventoryLedger\Support\WarehouseIdForInventoryResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InventoryKardexReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        if ($this->actingUserIsSuperAdmin()) {
            return true;
        }

        $warehouseId = $this->input('warehouse_id');
        if ($warehouseId === null || $warehouseId === '' || ! is_numeric($warehouseId)) {
            return false;
        }

        return WarehouseIdForInventoryResolver::userCanAccessWarehouse((int) $warehouseId, $user);
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'warehouse_id' => [
                'required',
                'integer',
                $this->actingUserIsSuperAdmin()
                    ? Rule::exists('warehouses', 'id')
                    : Rule::exists('warehouses', 'id')->where(
                        fn ($query) => $query->where('tenant_id', (int) $this->user()?->tenant_id),
                    ),
            ],
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'product_size_id' => [
                'required',
                'integer',
                Rule::exists('product_size', 'id')->where(
                    fn ($query) => $query->where(
                        'product_id',
                        $this->integer('product_id'),
                    ),
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

    private function actingUserIsSuperAdmin(): bool
    {
        $user = $this->user();

        return $user !== null
            && method_exists($user, 'hasRole')
            && $user->hasRole('Super Admin');
    }
}
