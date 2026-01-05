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
        // Totales Rápidos
        $salesTotals = $this->reportsService->getSalesTotals();

        // Top Productos
        $topProducts = $this->reportsService->getTopProducts(20);

        // Reporte Financiero (Por defecto mes actual si no envían fechas)
        $startDate = $request->query('start_date');
        $endDate = $request->query('end_date');
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
