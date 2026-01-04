<?php

namespace App\Finance\FinancialSummary\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => 'required|in:INGRESO,GASTO',
            'amount' => 'required|numeric|min:0.1',
            'category' => 'required|array',
            'category.name' => 'required|string',
        ];
    }

    /**
     * Preparamos los datos para el Servicio.
     * Aquí convertimos lo que llega del Front a lo que espera la BD.
     */
    public function validated($key = null, $default = null)
    {
        $data = parent::validated();

        return [
            // Mapeamos INGRESO -> INCOME, GASTO -> EXPENSE
            'type' => $data['type'] === 'INGRESO' ? 'INCOME' : 'EXPENSE',

            'amount' => $data['amount'],

            // Usamos el nombre de la categoría como descripción
            'description' => $data['category']['name'],

            // Por defecto asumimos efectivo para movimientos manuales de caja
            'payment_method' => 'CASH'
        ];
    }
}
