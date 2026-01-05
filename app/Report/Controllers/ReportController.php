<?php

namespace App\Report\Controllers;

use App\Report\Services\ReportService;
use App\Shared\Foundation\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    protected ReportService $reportsService;

    public function __construct(ReportService $reportsService)
    {
        $this->reportsService = $reportsService;
    }

    public function index(Request $request): JsonResponse
    {
        // 1. Recibimos las fechas PRIMERO
        $startDate = $request->query('start_date');
        $endDate = $request->query('end_date');

        // 2. Totales Rápidos
        // Pasamos $startDate para que sepa de qué mes/día calcular
        $salesTotals = $this->reportsService->getSalesTotals($startDate);

        // 3. Top Productos
        // Pasamos el rango completo para que filtre los más vendidos de ESE periodo
        $topProducts = $this->reportsService->getTopProducts(20, $startDate, $endDate);

        // 4. Reporte Financiero
        $financials = $this->reportsService->getFinancialReport($startDate, $endDate);

        return response()->json([
            'success' => true,
            'data' => [
                'totals' => $salesTotals,
                'top_products' => $topProducts,
                'financials' => $financials
            ]
        ]);
    }
}
