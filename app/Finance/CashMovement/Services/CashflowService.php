<?php

namespace App\Finance\CashMovement\Services;

use App\Finance\CashMovement\Models\CashMovement;
use App\Finance\Sale\Models\Sale;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;

class CashflowService
{
    private string $uploaderUrl;
    private string $apiKey;

    public function __construct()
    {
        $this->uploaderUrl = config('services.uploader.url');
        $this->apiKey = config('services.uploader.api_key');
    }

    /**
     * Obtiene el reporte completo de caja para una fecha específica.
     */

    public function getDailyReport(string $date, array $activeFilters = ['CASH', 'YAPE', 'CARD'])
    {
        $startOfDay = Carbon::parse($date)->startOfDay();
        $endOfDay = Carbon::parse($date)->endOfDay();

        // 1. OBTENER VENTAS (SALES)
        $sales = Sale::whereBetween('creation_time', [$startOfDay, $endOfDay])
            ->where('status', 'COMPLETED')
            ->where('is_deleted', false)
            ->with(['payments', 'details'])
            ->get()
            ->map(function ($sale) use ($activeFilters) {
                // AQUÍ ESTÁ EL TRUCO: Solo sumamos los pagos que coinciden con los filtros activos
                $filteredAmount = $sale->payments
                    ->whereIn('method', $activeFilters)
                    ->sum('amount');

                // Si el monto filtrado es 0 (ej: venta es solo CARD y solo filtraste CASH), no la mostramos
                if ($filteredAmount <= 0)
                    return null;

                $itemsDescription = $sale->details->map(function ($detail) {
                    return "{$detail->product_name_snapshot} | {$detail->size_name_snapshot} | {$detail->color_name_snapshot}";
                })->implode(' + ');

                return [
                    'id' => $sale->id,
                    'type' => 'SALE',
                    'time' => $sale->creation_time->format('H:i A'),
                    'description' => "{$sale->code} | {$itemsDescription}",
                    'method' => $sale->payment_method, // Sigue diciendo MIXTO si lo es
                    'amount' => (float) $filteredAmount, // Pero el monto es PARCIAL según el filtro
                ];
            })->filter()->values();

        // 2. OBTENER MOVIMIENTOS MANUALES (Filtrados también)
        $movements = CashMovement::whereBetween('date', [$startOfDay, $endOfDay])
            ->where('is_deleted', false)
            ->where('category', 'STORE')
            ->whereIn('payment_method', $activeFilters) // Filtro para ingresos/gastos manuales
            ->orderBy('date', 'desc')
            ->get()
            ->map(function ($mov) {
                return [
                    'id' => $mov->id,
                    'type' => $mov->type,
                    'time' => $mov->date->format('H:i A'),
                    'description' => $mov->description,
                    'method' => $mov->payment_method,
                    'amount' => (float) $mov->amount,
                ];
            });

        $incomes = $movements->where('type', 'INCOME')->values();
        $expenses = $movements->where('type', 'EXPENSE')->values();

        // 3. CÁLCULOS (Solo de lo que se está viendo)
        $totalSales = $sales->sum('amount');
        $totalIncomes = $incomes->sum('amount');
        $totalExpenses = $expenses->sum('amount');

        // Mantenemos los 100 de apertura pero para el cálculo final los ignoraremos en el Front
        $openingBalance = 100.00;

        return [
            'summary' => [
                'opening_balance' => $openingBalance,
                'total_sales' => $totalSales,
                'total_incomes' => $totalIncomes,
                'total_expenses' => $totalExpenses,
            ],
            'lists' => [
                'sales' => $sales,
                'incomes' => $incomes,
                'expenses' => $expenses
            ]
        ];
    }

    /**
     * Obtiene los gastos administrativos filtrados por mes y año.
     * Formato esperado de $month: 'YYYY-MM' (ej: '2026-04')
     */
    public function getMonthlyAdminExpenses(string $month)
    {
        $date = Carbon::parse($month);
        $year = $date->year;
        $monthNum = $date->month;

        $expenses = CashMovement::whereYear('date', $year)
            ->whereMonth('date', $monthNum)
            ->where('category', 'ADMINISTRATIVE')
            ->where('type', 'EXPENSE')
            ->where('is_deleted', false)
            ->orderBy('date', 'desc')
            ->get()
            ->map(function ($mov) {
                return [
                    'id' => $mov->id,
                    'date' => $mov->date->format('Y-m-d H:i:s'),
                    'description' => $mov->description,
                    'amount' => (float) $mov->amount,
                    'method' => $mov->payment_method,
                    'voucher_path' => $mov->voucher_path,
                ];
            });

        return [
            'month' => $date->format('F Y'),
            'total_monthly_admin' => $expenses->sum('amount'),
            'expenses' => $expenses
        ];
    }

    /**
     * Registra un movimiento manual (Gasto o Ingreso)
     */
    public function registerMovement(array $data, ?UploadedFile $image = null)
    {
        if ($image) {
            $data['voucher_path'] = $this->uploadToNode($image);
        }

        return CashMovement::create([
            'type' => $data['type'],
            'category' => $data['category'],
            'amount' => $data['amount'],
            'description' => $data['description'],
            'voucher_path' => $data['voucher_path'] ?? null,
            'payment_method' => $data['payment_method'] ?? 'CASH',
            'creator_user_id' => auth()->id() ?? 1,
            'date' => $data['date'],
        ]);
    }

    public function updateMovement(int $id, array $data, ?UploadedFile $newImage = null)
    {
        $movement = CashMovement::findOrFail($id);

        if ($newImage) {
            // 1. Borramos el anterior si existe
            if ($movement->voucher_path) {
                $this->deleteFromNode($movement->voucher_path);
            }
            // 2. Subimos el nuevo
            $data['voucher_path'] = $this->uploadToNode($newImage);
        }

        $movement->update([
            'type' => $data['type'] ?? $movement->type,
            'category' => $data['category'] ?? $movement->category,
            'amount' => $data['amount'] ?? $movement->amount,
            'description' => $data['description'] ?? $movement->description,
            'voucher_path' => $data['voucher_path'] ?? $movement->voucher_path,
            'payment_method' => $data['payment_method'] ?? $movement->payment_method,
            'last_modifier_user_id' => auth()->id() ?? 1,
            'last_modification_time' => now(),
            'date' => $data['date'] ?? $movement->date,
        ]);

        return $movement;
    }

    private function uploadToNode(UploadedFile $file): ?string
    {
        $response = Http::withHeaders(['X-API-KEY' => $this->apiKey])
            ->attach('files', file_get_contents($file->getRealPath()), $file->getClientOriginalName())
            ->post($this->uploaderUrl . '/api/upload', ['context' => 'vouchers']);

        return $response->successful() ? $response->json()['files'][0]['url'] : null;
    }

    /**
     * Lógica privada para borrar en Node.js
     */
    private function deleteFromNode(string $path): void
    {
        Http::withHeaders(['X-API-KEY' => $this->apiKey])
            ->delete($this->uploaderUrl . '/api/delete', ['path' => $path]);
    }
}
