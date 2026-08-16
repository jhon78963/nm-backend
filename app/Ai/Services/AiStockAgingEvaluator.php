<?php

namespace App\Ai\Services;

class AiStockAgingEvaluator
{
    /**
     * @param  array{
     *   product_age_days:int,
     *   days_since_last_sale:int,
     *   sales_last_month:int,
     *   current_stock:int,
     *   total_sales_all_time:int
     * }  $signals
     * @return array{is_dead_stock:bool,dead_stock_tier:string,dead_stock_label:string}
     */
    public function evaluate(array $signals): array
    {
        $age = (int) $signals['product_age_days'];
        $idle = (int) $signals['days_since_last_sale'];
        $salesMonth = (int) $signals['sales_last_month'];
        $stock = (int) $signals['current_stock'];
        $totalSales = (int) $signals['total_sales_all_time'];

        if ($age >= 90 && $totalSales === 0 && $stock >= 5) {
            return $this->result(true, 'critical', 'Sin ventas registradas: liquidación urgente recomendada.');
        }

        if ($age >= 180 && $salesMonth <= 4 && $stock >= 10 && $idle >= 30) {
            return $this->result(true, 'critical', 'Atacasco crítico: antigüedad alta, stock elevado y ventas mínimas.');
        }

        if ($age >= 120 && $salesMonth < 5 && $stock >= 5 && $idle >= 45) {
            return $this->result(true, 'high', 'Producto estancado: se recomienda rebaja fuerte para liberar capital.');
        }

        if ($age >= 90 && $totalSales <= 10 && $stock >= 10 && $idle >= 30) {
            return $this->result(true, 'aging', 'Rotación muy lenta: descuento adicional para acelerar salida.');
        }

        return $this->result(false, 'none', '');
    }

    /**
     * @return array{is_dead_stock:bool,dead_stock_tier:string,dead_stock_label:string}
     */
    private function result(bool $isDeadStock, string $tier, string $label): array
    {
        return [
            'is_dead_stock' => $isDeadStock,
            'dead_stock_tier' => $tier,
            'dead_stock_label' => $label,
        ];
    }
}
