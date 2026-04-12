<?php

namespace App\Finance\CashMovement\Controllers;

use App\Finance\CashMovement\Services\CashflowService;
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

    public function getAdminMonthlyReport(Request $request): JsonResponse
    {
        // Validamos que venga un mes, si no, usamos el actual
        $month = $request->query('month', now()->format('Y-m'));

        $report = $this->cashflowService->getMonthlyAdminExpenses($month);

        return response()->json([
            'success' => true,
            'data' => $report
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => 'required|in:INCOME,EXPENSE',
            'category' => 'required|in:ADMINISTRATIVE,STORE',
            'amount' => 'required|numeric',
            'description' => 'required|string',
            'date' => 'required|date',
            'image' => 'nullable|image',
            'payment_method' => 'nullable|string',
        ]);

        // Pasamos los datos y el archivo (si existe)
        $movement = $this->cashflowService->registerMovement($data, $request->file('image'));

        return response()->json(['success' => true, 'data' => $movement]);
    }

public function update(Request $request, $id): JsonResponse
{
    $data = $request->validate([
        'type'           => 'nullable|in:INCOME,EXPENSE',
        'category'       => 'nullable|in:ADMINISTRATIVE,STORE',
        'amount'         => 'nullable|numeric',
        'description'    => 'nullable|string',
        'date'           => 'nullable|date',
        'payment_method' => 'nullable|string',
        'image'          => 'nullable|image|max:5120', // 5MB
    ]);

    $movement = $this->cashflowService->updateMovement($id, $data, $request->file('image'));

    return response()->json(['success' => true, 'data' => $movement]);
}
}
