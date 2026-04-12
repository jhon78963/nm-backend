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
    public function getDailyReport(string $date)
    {
        $startOfDay = Carbon::parse($date)->startOfDay();
        $endOfDay = Carbon::parse($date)->endOfDay();

        // 1. OBTENER VENTAS (SALES) - Se mantiene igual
        $sales = Sale::whereBetween('creation_time', [$startOfDay, $endOfDay])
            ->where('status', 'COMPLETED')
            ->where('is_deleted', false)
            ->with(['payments', 'details'])
            ->orderBy('creation_time', 'desc')
            ->get()
            ->map(function ($sale) {
                $itemsDescription = $sale->details->map(function ($detail) {
                    return "{$detail->product_name_snapshot} | {$detail->size_name_snapshot} | {$detail->color_name_snapshot}";
                })->implode(' + ');
                return [
                    'id' => $sale->id,
                    'type' => 'SALE',
                    'time' => $sale->creation_time->format('H:i A'),
                    'description' => "{$sale->code} | {$itemsDescription}",
                    'method' => $sale->payment_method,
                    'amount' => (float) $sale->total_amount,
                ];
            });

        // 2. OBTENER MOVIMIENTOS MANUALES (FILTRADOS POR CATEGORÍA STORE)
        // Agregamos: ->where('category', 'STORE')
        $movements = CashMovement::whereBetween('date', [$startOfDay, $endOfDay])
            ->where('is_deleted', false)
            ->where('category', 'STORE') // <--- FILTRO CLAVE PARA LA CAJA
            ->orderBy('date', 'desc')
            ->get()
            ->map(function ($mov) {
                return [
                    'id' => $mov->id,
                    'type' => $mov->type,
                    'category' => $mov->category, // Agregado
                    'time' => $mov->date->format('H:i A'),
                    'description' => $mov->description,
                    'method' => $mov->payment_method,
                    'amount' => (float) $mov->amount,
                    'voucher_path' => $mov->voucher_path // IMPORTANTE para el link
                ];
            });

        // 3. AGRUPAR Y CALCULAR TOTALES (Solo de la tienda)
        $incomes = $movements->where('type', 'INCOME')->values();
        $expenses = $movements->where('type', 'EXPENSE')->values();

        $totalSales = $sales->sum('amount');
        $totalIncomes = $incomes->sum('amount');
        $totalExpenses = $expenses->sum('amount');

        $openingBalance = 100.00; // Podrías traer esto de una tabla de aperturas
        $finalBalance = $openingBalance + $totalSales + $totalIncomes - $totalExpenses;

        return [
            'date' => Carbon::parse($date)->format('d/m/Y'),
            'summary' => [
                'opening_balance' => $openingBalance,
                'total_sales' => $totalSales,
                'total_incomes' => $totalIncomes,
                'total_expenses' => $totalExpenses,
                'final_balance' => $finalBalance
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
