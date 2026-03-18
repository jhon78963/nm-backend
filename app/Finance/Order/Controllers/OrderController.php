<?php

namespace App\Finance\Order\Controllers;

use App\Finance\Order\Requests\StoreOrderRequest;
use App\Finance\Order\Services\OrderService;
use App\Shared\Foundation\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class OrderController extends Controller
{
    public function __construct(
        protected OrderService $orderService,
    ) {
    }

    public function create(StoreOrderRequest $request): JsonResponse
    {
        try {
            $order = $this->orderService->processOrder($request->validated());

            return response()->json([
                'success' => true,
                'sale_id' => $order->id,
                'message' => 'Orden procesada correctamente',
            ], 201);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}
