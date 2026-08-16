<?php

namespace App\Ai\Controllers;

use App\Ai\Services\AiEngineClient;
use App\Ai\Services\AiProductContextService;
use App\Inventory\Product\Models\Product;
use App\Shared\Foundation\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class AiPredictionController extends Controller
{
    public function __construct(
        private readonly AiProductContextService $contextService,
        private readonly AiEngineClient $engineClient,
    ) {}

    public function productContext(Product $product): JsonResponse
    {
        $context = $this->contextService->buildContext(
            $product,
            $this->canViewPurchasePrice(),
        );

        return response()->json($context);
    }

    public function predictPrice(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
        ]);

        $product = Product::query()->findOrFail((int) $validated['product_id']);
        $context = $this->contextService->buildContext(
            $product,
            $this->canViewPurchasePrice(),
        );

        try {
            $result = $this->engineClient->predictPrice(
                $this->contextService->toPriceEnginePayload($context),
            );
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 502);
        }

        return response()->json($result);
    }

    public function predictDemand(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'horizon_days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        $product = Product::query()->findOrFail((int) $validated['product_id']);
        $horizonDays = (int) ($validated['horizon_days'] ?? 30);
        $context = $this->contextService->buildContext(
            $product,
            $this->canViewPurchasePrice(),
        );

        try {
            $result = $this->engineClient->predictDemand(
                $this->contextService->toDemandEnginePayload($context, $horizonDays),
            );
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 502);
        }

        return response()->json($result);
    }

    private function canViewPurchasePrice(): bool
    {
        $user = auth()->user();

        return $user !== null && $user->can('product.view_purchase_price');
    }
}
