<?php

namespace App\Ai\Controllers;

use App\Ai\Services\AiProductsInventoryReportService;
use App\Shared\Foundation\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class AiReportController extends Controller
{
    public function __construct(
        private readonly AiProductsInventoryReportService $inventoryReportService,
    ) {}

    public function productsInventory(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'horizon_days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        $horizonDays = (int) ($validated['horizon_days'] ?? 30);

        try {
            $report = $this->inventoryReportService->build(
                $horizonDays,
                $this->canViewPurchasePrice(),
            );
        } catch (RuntimeException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
                'error' => 'AI_ENGINE_ERROR',
            ], 502);
        }

        return response()->json([
            'success' => true,
            'data' => $report,
        ]);
    }

    private function canViewPurchasePrice(): bool
    {
        $user = auth()->user();

        return $user !== null && $user->can('product.view_purchase_price');
    }
}
