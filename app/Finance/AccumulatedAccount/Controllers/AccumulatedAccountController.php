<?php

namespace App\Finance\AccumulatedAccount\Controllers;

use App\Finance\AccumulatedAccount\Requests\InitializeAccumulatedAccountSettingsRequest;
use App\Finance\AccumulatedAccount\Requests\MonthEndTransferRequest;
use App\Finance\AccumulatedAccount\Requests\UpdateAccumulatedAccountSettingsRequest;
use App\Finance\AccumulatedAccount\Services\AccumulatedAccountService;
use App\Shared\Foundation\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class AccumulatedAccountController extends Controller
{
    public function __construct(
        protected AccumulatedAccountService $accumulatedAccountService,
    ) {
    }

    public function showSettings(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->accumulatedAccountService->getSettings(),
        ]);
    }

    public function monthEndTransferPreview(\Illuminate\Http\Request $request): JsonResponse
    {
        $month = $request->query('month', now()->format('Y-m'));

        return response()->json([
            'success' => true,
            'data' => $this->accumulatedAccountService->getMonthEndTransferPreview((string) $month),
        ]);
    }

    public function listMonthEndTransfers(\Illuminate\Http\Request $request): JsonResponse
    {
        $month = $request->query('month');
        $limit = (int) $request->query('limit', 12);

        return response()->json([
            'success' => true,
            'data' => $this->accumulatedAccountService->listMonthEndTransfers(
                is_string($month) ? $month : null,
                max(1, min($limit, 48)),
            ),
        ]);
    }

    public function recordMonthEndTransfer(MonthEndTransferRequest $request): JsonResponse
    {
        $transfer = $this->accumulatedAccountService->recordMonthEndTransfer($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Traspaso registrado. El fondo acumulado fue incrementado.',
            'data' => [
                'preview' => $this->accumulatedAccountService->getMonthEndTransferPreview($transfer->transfer_month),
                'settings' => $this->accumulatedAccountService->getSettings(),
            ],
        ], 201);
    }

    public function updateSettings(UpdateAccumulatedAccountSettingsRequest $request): JsonResponse
    {
        $this->accumulatedAccountService->updateSettings($request->validated());

        return response()->json([
            'success' => true,
            'data' => $this->accumulatedAccountService->getSettings(),
        ]);
    }

    public function initializeSettings(InitializeAccumulatedAccountSettingsRequest $request): JsonResponse
    {
        $this->accumulatedAccountService->initializeSettings($request->validated());

        return response()->json([
            'success' => true,
            'data' => $this->accumulatedAccountService->getSettings(),
        ]);
    }
}
