<?php

namespace App\Ai\Controllers;

use App\Ai\Services\AiEngineService;
use App\Ai\Services\AiProductContextService;
use App\Inventories\Products\Models\Product;
use App\Inventories\Products\Services\ProductService;
use App\Shared\Foundation\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class AiPredictionController extends Controller
{
    public function __construct(
        private readonly AiEngineService $aiEngineService,
        private readonly AiProductContextService $aiProductContextService,
        private readonly ProductService $productService,
    ) {}

    /**
     * Contexto del producto para el asistente IA (costo, categoría, ventas, stock).
     */
    public function productContext(Product $product): JsonResponse
    {
        $this->productService->validate($product, 'Product');

        return response()->json(
            $this->aiProductContextService->resolve($product, request()),
        );
    }

    /**
     * Optimiza el precio de un producto vía nm_ai_engine.
     * Los parámetros se resuelven desde la base de datos usando product_id.
     */
    public function optimizePrice(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => ['required', 'integer', 'min:1'],
        ]);

        $product = Product::query()->findOrFail($validated['product_id']);
        $this->productService->validate($product, 'Product');

        try {
            $payload = $this->aiProductContextService->buildPricePayload($product, $request);
            $result = $this->aiEngineService->optimizePrice($payload);
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
     * Stock y producto se resuelven desde la base de datos.
     */
    public function predictDemand(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => ['required', 'integer', 'min:1'],
            'horizon_days' => ['sometimes', 'integer', 'min:1', 'max:365'],
        ]);

        $product = Product::query()->findOrFail($validated['product_id']);
        $this->productService->validate($product, 'Product');

        try {
            $payload = $this->aiProductContextService->buildDemandPayload(
                $product,
                $request,
                (int) ($validated['horizon_days'] ?? 30),
            );
            $result = $this->aiEngineService->predictDemand($payload);
        } catch (RuntimeException $exception) {
            return response()->json(
                ['message' => $exception->getMessage()],
                Response::HTTP_BAD_GATEWAY,
            );
        }

        return response()->json($result);
    }
}
