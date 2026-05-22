<?php

namespace App\Finance\Expense\Requests\Concerns;

use App\Shared\Foundation\Support\AuthenticatedUserWarehouseResolver;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

trait ValidatesExpenseUserId
{
    /**
     * @return array<int, mixed>
     */
    protected function expenseUserIdRules(bool $required): array
    {
        $rules = $required ? ['required', 'integer'] : ['sometimes', 'integer'];

        $warehouseId = AuthenticatedUserWarehouseResolver::resolve();

        $existsRule = Rule::exists('users', 'id')->where(function ($query) use ($warehouseId): void {
            $query->where('is_deleted', false);

            if ($warehouseId > 0) {
                $query->where('warehouse_id', $warehouseId);
            }
        });

        $rules[] = $existsRule;

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->filled('userId')) {
                return;
            }

            $warehouseId = AuthenticatedUserWarehouseResolver::resolve();
            if ($warehouseId <= 0) {
                $validator->errors()->add(
                    'userId',
                    'No se pudo determinar el almacén operativo para validar el usuario.',
                );
            }
        });
    }
}
