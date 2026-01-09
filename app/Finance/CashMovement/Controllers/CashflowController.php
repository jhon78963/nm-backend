<?php

namespace App\Finance\CashMovement\Controllers;

use App\Finance\CashMovement\Services\CashflowService;
use App\Finance\FinancialSummary\Requests\StoreTransactionRequest;
use App\Shared\Foundation\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class CashflowController extends Controller
{
    protected $cashflowService;

    public function __construct(CashflowService $cashflowService)
    {
        $this->cashflowService = $cashflowService;
    }

    public function getDaily(Request $request): JsonResponse
    {
        // Si no envía fecha, usamos hoy
        $date = $request->query('date', now()->format('Y-m-d'));

        $report = $this->cashflowService->getDailyReport($date);

        return response()->json([
            'success' => true,
            'data' => $report
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => 'required|in:INCOME,EXPENSE',
            'amount' => 'required|numeric|min:0.1',
            'description' => 'required|string|max:255',
            'payment_method' => 'nullable|string'
        ]);

        $this->cashflowService->registerMovement($data);
        return response()->json(['success' => true, 'message' => 'Movimiento registrado']);
    }
}
