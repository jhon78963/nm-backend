<?php

namespace App\Finance\Order\Controllers;

use App\Finance\Order\Models\Order;
use App\Finance\Order\Requests\StoreOrderRequest;
use App\Finance\Order\Requests\UpdateOrderRequest;
use App\Finance\Order\Resources\OrderDetailResource;
use App\Finance\Order\Resources\OrderResource;
use App\Finance\Order\Services\OrderService;
use App\Shared\Foundation\Controllers\Controller;
use App\Shared\Foundation\Requests\GetAllRequest;
use App\Shared\Foundation\Resources\GetAllCollection;
use App\Shared\Foundation\Services\SharedService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    public function __construct(
        protected OrderService $orderService,
        protected SharedService $sharedService,
    ) {
    }

    public function create(StoreOrderRequest $request): JsonResponse
    {
        try {
            $data = $this->sharedService->convertCamelToSnake($request->validated());
            $order = $this->orderService->processOrder($data);

            return response()->json([
                'success' => true,
                'order_id' => $order->id,
                'message' => 'Orden procesada correctamente',
            ], 201);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function delete(Order $order): JsonResponse
    {
        return DB::transaction(function () use ($order): JsonResponse {
            $this->orderService->validate($order, 'Order');
            $this->orderService->delete($order);

            return response()->json(['message' => 'Order deleted successfully.']);
        });
    }

    public function get(Order $order): JsonResponse
    {
        $this->orderService->validate($order, 'Order');
        return response()->json(new OrderDetailResource($order));
    }

    public function getAll(GetAllRequest $request): JsonResponse
    {
        $query = $this->sharedService->query(
            request: $request,
            entityName: 'Finance\\Order',
            modelName: 'Order',
            columnSearch: ['id', 'code', 'reference_date', 'status', 'type'],
            orderBy: 'reference_date',
            orderDir: 'desc',
        );

        return response()->json(new GetAllCollection(
            OrderResource::collection($query['collection']),
            $query['total'],
            $query['pages'],
        ));
    }

    public function update(UpdateOrderRequest $request, Order $order): JsonResponse
    {
        return DB::transaction(function () use ($request, $order): JsonResponse {
            $this->orderService->validate($order, 'Order');
            $data = $this->sharedService->convertCamelToSnake($request->validated());
            $this->orderService->update($order, $data);

            return response()->json(['message' => 'Order updated.'], 200);
        });
    }
}
