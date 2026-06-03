<?php

namespace App\Finance\CashMovement\Resources;

use App\Finance\CashMovement\Models\CashMovement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CashMovement
 */
class CashMovementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'category' => $this->category,
            'amount' => (float) $this->amount,
            'description' => $this->description,
            'payment_method' => $this->payment_method,
            'method' => $this->payment_method,
            'date' => $this->date?->format('Y-m-d H:i:s'),
            'accounting_month' => $this->accounting_month,
            'payroll_period' => $this->payroll_period,
            'accounting_period_label' => $this->accountingPeriodLabel(),
            'time' => $this->date?->format('H:i A'),
            'voucher_path' => $this->voucher_path,
            'voucher_paths' => $this->whenLoaded(
                'vouchers',
                fn () => $this->vouchers->pluck('voucher_path')->values(),
                fn () => ($this->voucher_path ? [$this->voucher_path] : []),
            ),
            'purchase_id' => $this->purchase_id,
            'expense_category' => $this->expense_category,
            'reference_code' => $this->reference_code,
        ];
    }
}
