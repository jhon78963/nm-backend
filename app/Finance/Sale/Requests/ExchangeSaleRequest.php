<?php

namespace App\Finance\Sale\Requests;

use App\Inventory\InventoryLedger\Support\WarehouseIdForInventoryResolver;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

class ExchangeSaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $warehouseId = $this->operativeWarehouseId();

        return [
            'returned_detail_id' => [
                'required',
                'integer',
                $this->scopedReturnedDetailExistsRule($warehouseId),
            ],
            'difference_amount' => 'required|decimal:0,2|min:0',
            'payment_method' => 'nullable|string',
            'new_item.product_size_id' => 'required|integer',
            'new_item.color_id' => 'required|integer',
            'new_item.final_price' => 'required|decimal:0,2|min:0',
        ];
    }

    private function operativeWarehouseId(): int
    {
        $user = Auth::user();
        $userWarehouseId = (int) ($user?->warehouse_id ?? 0);

        if ($userWarehouseId > 0) {
            return $userWarehouseId;
        }

        if ($this->actingUserIsSuperAdmin()) {
            $explicitWarehouseId = WarehouseIdForInventoryResolver::explicitFromRequest($this);
            if (
                $explicitWarehouseId > 0
                && WarehouseIdForInventoryResolver::userCanAccessWarehouse($explicitWarehouseId, $user)
            ) {
                return $explicitWarehouseId;
            }
        }

        return 0;
    }

    private function actingUserIsSuperAdmin(): bool
    {
        $user = Auth::user();

        return $user !== null
            && method_exists($user, 'hasRole')
            && $user->hasRole('Super Admin');
    }

    private function scopedReturnedDetailExistsRule(int $warehouseId): Exists
    {
        if ($warehouseId > 0) {
            return Rule::exists('sale_details', 'id')->where(
                static fn ($query) => $query->whereIn('sale_id', function ($sub) use ($warehouseId) {
                    $sub->select('id')
                        ->from('sales')
                        ->where('warehouse_id', $warehouseId)
                        ->where('is_deleted', false);
                }),
            );
        }

        if ($this->actingUserIsSuperAdmin()) {
            return Rule::exists('sale_details', 'id');
        }

        return Rule::exists('sale_details', 'id')->where(static fn ($query) => $query->whereRaw('1 = 0'));
    }
}
