<?php

namespace App\Finance\Sale\Requests;

use App\Finance\Sale\Models\Sale;
use App\Inventory\InventoryLedger\Support\WarehouseIdForInventoryResolver;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

class CheckoutPosRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && $user->can('create', Sale::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $warehouseId = $this->operativeWarehouseId();

        return [
            'document_type' => 'required|string|in:BOLETA,FACTURA,TICKET_INTERNO',
            // serie obligatoria para BOLETA/FACTURA; omitida para TICKET_INTERNO
            'serie' => 'required_unless:document_type,TICKET_INTERNO|nullable|string|size:4',
            'customer' => 'nullable|array',
            'customer.id' => [
                'nullable',
                'integer',
                $this->scopedCustomerExistsRule($warehouseId),
            ],
            // El total se recalcula en servidor; el cliente puede enviarlo solo como referencia.
            'total' => 'nullable|decimal:0,2|min:0',
            'items' => 'required|array|min:1',
            'items.*.color.product_size_id' => [
                'required',
                'integer',
                $this->scopedProductSizeExistsRule($warehouseId),
            ],
            'items.*.color.color_id' => 'required|integer',
            'items.*.quantity' => 'required|integer|min:1',
            // El margen (precio > costo de compra) se valida en servidor; no se expone el costo al cliente.
            'items.*.unitPrice' => 'required|decimal:0,2|min:0',
            // Subtotal por línea ignorado en servidor (se deriva de cantidad × precio unitario).
            'items.*.total' => 'nullable|decimal:0,2|min:0',
            'payments' => 'required|array|min:1',
            'payments.*.method' => 'required|string|in:CASH,YAPE,CARD,TRANSFER',
            'payments.*.amount' => 'required|decimal:0,2|min:0',
            'payments.*.reference' => 'nullable|string',
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

    private function scopedProductSizeExistsRule(int $warehouseId): Exists
    {
        if ($warehouseId > 0) {
            return Rule::exists('product_size', 'id')->where(
                static fn ($query) => $query->whereIn('product_id', function ($sub) use ($warehouseId) {
                    $sub->select('id')
                        ->from('products')
                        ->where('warehouse_id', $warehouseId)
                        ->where('is_deleted', false);
                }),
            );
        }

        if ($this->actingUserIsSuperAdmin()) {
            return Rule::exists('product_size', 'id');
        }

        return Rule::exists('product_size', 'id')->where(static fn ($query) => $query->whereRaw('1 = 0'));
    }

    private function scopedCustomerExistsRule(int $warehouseId): Exists
    {
        if ($warehouseId > 0) {
            return Rule::exists('customers', 'id')->where(
                static fn ($query) => $query
                    ->where('warehouse_id', $warehouseId)
                    ->where('is_deleted', false),
            );
        }

        if ($this->actingUserIsSuperAdmin()) {
            return Rule::exists('customers', 'id')->where(
                static fn ($query) => $query->where('is_deleted', false),
            );
        }

        return Rule::exists('customers', 'id')->where(static fn ($query) => $query->whereRaw('1 = 0'));
    }
}
