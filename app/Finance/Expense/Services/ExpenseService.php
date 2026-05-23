<?php

namespace App\Finance\Expense\Services;

use App\Finance\Expense\Models\Expense;
use App\Shared\Foundation\Services\ModelService;
use Illuminate\Database\Eloquent\Model;

class ExpenseService extends ModelService
{
    public function __construct(Expense $expense)
    {
        parent::__construct($expense);
    }

    public function create(array $data): Model
    {
        return parent::create($this->mapPayload($data));
    }

    public function update(Model $model, array $data): Model
    {
        return parent::update($model, $this->mapPayload($data, partial: true));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function mapPayload(array $data, bool $partial = false): array
    {
        $mapped = [];

        if (array_key_exists('expense_date', $data)) {
            $mapped['date'] = $data['expense_date'];
        }

        if (array_key_exists('description', $data)) {
            $mapped['description'] = $data['description'];
        }

        if (array_key_exists('category', $data)) {
            $mapped['expense_category'] = $data['category'];
        }

        if (array_key_exists('amount', $data)) {
            $mapped['amount'] = $data['amount'];
        }

        if (array_key_exists('payment_method', $data)) {
            $mapped['payment_method'] = $this->normalizePaymentMethod($data['payment_method']);
        }

        if (array_key_exists('reference_code', $data)) {
            $mapped['reference_code'] = $data['reference_code'];
        }

        if (array_key_exists('user_id', $data)) {
            $mapped['creator_user_id'] = $data['user_id'];
        }

        if ($partial) {
            return $mapped;
        }

        return array_merge([
            'date' => $data['expense_date'] ?? now(),
            'description' => $data['description'],
            'expense_category' => $data['category'] ?? null,
            'amount' => $data['amount'],
            'payment_method' => $this->normalizePaymentMethod($data['payment_method'] ?? 'CASH'),
            'reference_code' => $data['reference_code'] ?? null,
            'creator_user_id' => $data['user_id'] ?? auth()->id(),
        ], $mapped);
    }

    private function normalizePaymentMethod(?string $method): string
    {
        $normalized = strtoupper(trim((string) $method));

        return match ($normalized) {
            'EFECTIVO', 'CASH' => 'CASH',
            'YAPE' => 'YAPE',
            'CARD', 'TARJETA', 'TRANSFER', 'TRANSFERENCIA' => 'CARD',
            default => $normalized !== '' ? $normalized : 'CASH',
        };
    }
}
