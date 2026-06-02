<?php

namespace App\Finance\CashMovement\Controllers;

use App\Administration\Audit\Services\UserActionLogService;
use App\Administration\Audit\Support\AuditActions;
use App\Finance\CashMovement\Models\CashMovement;
use App\Finance\CashMovement\Requests\CashflowStoreRequest;
use App\Finance\CashMovement\Requests\CashflowUpdateRequest;
use App\Finance\CashMovement\Resources\CashMovementResource;
use App\Finance\CashMovement\Services\CashflowService;
use App\Shared\Foundation\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

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
        $filters = $request->query('filters', ['CASH', 'YAPE', 'CARD']);
        if (is_string($filters)) {
            $filters = explode(',', $filters);
        }

        $report = $this->cashflowService->getDailyReport($date, $filters);

        return response()->json([
            'success' => true,
            'data' => [
                'summary' => $report['summary'],
                'lists' => [
                    'sales' => $report['lists']['sales'],
                    'incomes' => CashMovementResource::collection($report['lists']['incomes']),
                    'expenses' => CashMovementResource::collection($report['lists']['expenses']),
                ],
            ],
        ]);
    }

    public function getAdminMonthlyReport(Request $request): JsonResponse
    {
        // Validamos que venga un mes, si no, usamos el actual
        $month = $request->query('month', now()->format('Y-m'));

        $report = $this->cashflowService->getMonthlyAdminExpenses($month);

        return response()->json([
            'success' => true,
            'data' => [
                'month' => $report['month'],
                'total_monthly_admin' => $report['total_monthly_admin'],
                'expenses' => CashMovementResource::collection($report['expenses']),
            ],
        ]);
    }

    public function store(CashflowStoreRequest $request): JsonResponse
    {
        $data = $request->validated();

        // Pasamos los datos y el archivo (si existe)
        $movement = $this->cashflowService->registerMovement($data, $request->file('images') ?: null);

        UserActionLogService::log(
            AuditActions::CASHFLOW_CREATED,
            metadata: ['cash_movement_id' => $movement->id],
        );

        return response()->json([
            'success' => true,
            'data' => new CashMovementResource($movement),
        ]);
    }

    public function update(CashflowUpdateRequest $request, CashMovement $cashMovement): JsonResponse
    {
        if ($cashMovement->is_deleted) {
            abort(404, 'Movimiento no encontrado');
        }

        $data = $request->validated();

        $movement = $this->cashflowService->updateMovement(
            $cashMovement->id,
            $data,
            $request->file('images') ?: null,
        );

        UserActionLogService::log(
            AuditActions::CASHFLOW_UPDATED,
            metadata: ['cash_movement_id' => $movement->id],
        );

        return response()->json([
            'success' => true,
            'data' => new CashMovementResource($movement),
        ]);
    }

    public function streamVoucher(Request $request): Response
    {
        $path = $request->query('path');

        if (! is_string($path) || trim($path) === '') {
            abort(400, 'Path requerido.');
        }

        $file = $this->cashflowService->streamVoucher($path);

        return response($file['body'], 200, [
            'Content-Type' => $file['content_type'],
            'Content-Disposition' => 'inline; filename="'.$file['filename'].'"',
        ]);
    }
}
