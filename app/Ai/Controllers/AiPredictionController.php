<?php

namespace App\Ai\Controllers;

use App\Ai\Services\AiEngineService;
use App\Shared\Foundation\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class AiPredictionController extends Controller
{
    public function __construct(
        private readonly AiEngineService $aiEngineService,
    ) {}

    /**
     * Optimiza el precio de un producto vía nm_ai_engine.
     */
    public function optimizePrice(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => ['required', 'integer', 'min:1'],
            'current_cost' => ['required', 'numeric', 'min:0'],
            'category' => ['required', 'string', 'min:1'],
            'sales_last_month' => ['required', 'integer', 'min:0'],
        ]);

        try {
            $result = $this->aiEngineService->optimizePrice($validated);
        } catch (RuntimeException $exception) {
            return response()->json(
                ['message' => $exception->getMessage()],
                Response::HTTP_BAD_GATEWAY,
            );
        }

        return response()->json($result);
    }

    /**
     * Predice demanda y cantidad sugerida de compra vía nm_ai_engine.
     */
    public function predictDemand(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => ['required', 'integer', 'min:1'],
            'current_stock' => ['required', 'integer', 'min:0'],
            'horizon_days' => ['sometimes', 'integer', 'min:1', 'max:365'],
        ]);

        try {
            $result = $this->aiEngineService->predictDemand($validated);
        } catch (RuntimeException $exception) {
            return response()->json(
                ['message' => $exception->getMessage()],
                Response::HTTP_BAD_GATEWAY,
            );
        }

        return response()->json($result);
    }
}
