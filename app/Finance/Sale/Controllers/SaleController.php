<?php

namespace App\Finance\Sale\Controllers;

use App\Finance\Sale\Models\Sale;
use App\Finance\Sale\Requests\ExchangeSaleRequest;
use App\Finance\Sale\Requests\SaleUpdateRequest;
use App\Finance\Sale\Resources\SaleDetailResource;
use App\Finance\Sale\Resources\SaleResource;
use App\Finance\Sale\Services\SaleService;
use App\Shared\Foundation\Controllers\Controller;
use App\Shared\Foundation\Requests\GetAllRequest;
use App\Shared\Foundation\Resources\GetAllCollection;
use App\Shared\Foundation\Services\SharedService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

class SaleController extends Controller
{
    public function __construct(
        protected SaleService $saleService,
        protected SharedService $sharedService,
    ) {
    }

    public function getMonthlyStats(): JsonResponse
    {
        $stats = $this->saleService->getMonthlyStats();

        return response()->json($stats);
    }

    public function delete(Sale $sale): JsonResponse
    {
        return DB::transaction(function () use ($sale): JsonResponse {
            $this->saleService->validate($sale, 'Sale');
            $this->saleService->delete($sale);

            return response()->json(['message' => 'Sale deleted successfully.']);
        });
    }

    public function get(Sale $sale): JsonResponse
    {
        $this->saleService->validate($sale, 'Sale');
        $sale->load(['details', 'payments', 'customer']);

        return response()->json(new SaleDetailResource($sale));
    }

    public function getAll(GetAllRequest $request): JsonResponse
    {
        $query = $this->sharedService->query(
            request: $request,
            entityName: 'Finance\\Sale',
            modelName: 'Sale',
            columnSearch: ['id', 'code', 'creation_time', 'status', 'payment_method', 'customer.name'],
            filters: [],
            extendQuery: fn ($q) => $q->with('customer'),
            orderBy: 'creation_time',
            orderDir: 'desc',
        );

        return response()->json(new GetAllCollection(
            SaleResource::collection($query['collection']),
            $query['total'],
            $query['pages'],
        ));
    }

    public function update(SaleUpdateRequest $request, Sale $sale): JsonResponse
    {
        return DB::transaction(function () use ($request, $sale): JsonResponse {
            $this->saleService->validate($sale, 'Sale');
            $data = $this->sharedService->convertCamelToSnake($request->validated());
            $this->saleService->update($sale, $data);

            return response()->json(['message' => 'Sale updated.'], 200);
        });
    }

    public function exchange(ExchangeSaleRequest $request): JsonResponse
    {
        try {
            $this->saleService->processExchange($request->validated());

            return response()->json([
                'success' => true,
                'message' => 'Cambio registrado correctamente',
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
