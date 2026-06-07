<?php

namespace App\Finance\AccumulatedAccount\Controllers;

use App\Finance\AccumulatedAccount\Requests\InitializeAccumulatedAccountSettingsRequest;
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
