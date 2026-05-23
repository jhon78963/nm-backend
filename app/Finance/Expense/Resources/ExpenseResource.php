<?php

namespace App\Finance\Expense\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** * @mixin Expense
 * @property int $id
 * @property mixed $expense_date
 * @property string $description
 * @property string $category
 * @property float $amount
 * @property string $payment_method
 * @property string $reference_code
 * @property int $user_id
 */
class ExpenseResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'expenseDate' => $this->date?->format('d/m/Y H:i') ?? '---',
            'description' => $this->description,
            'category' => $this->expense_category,
            'amount' => $this->amount,
            'paymentMethod' => $this->payment_method,
            'referenceCode' => $this->reference_code,
            'user' => $this->creator
                ? $this->creator->name . ' ' . $this->creator->surname
                : '---',
            'userId' => $this->creator_user_id,
        ];
    }
}
