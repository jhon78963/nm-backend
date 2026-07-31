<?php

namespace App\Administrations\Warehouses\Controllers;

use App\Administrations\Warehouses\Models\Warehouse;
use App\Administrations\Warehouses\Requests\WarehouseCreateRequest;
use App\Administrations\Warehouses\Requests\WarehouseUpdateRequest;
use App\Administrations\Warehouses\Resources\WarehouseResource;
use App\Administrations\Warehouses\Services\WarehouseService;
use App\Shared\Foundation\Controllers\Controller;
use App\Shared\Foundation\Requests\GetAllRequest;
use App\Shared\Foundation\Resources\GetAllCollection;
use App\Shared\Foundation\Services\SharedService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class WarehouseController extends Controller
{
    public function __construct(
        protected WarehouseService $warehouseService,
        protected SharedService $sharedService,
    ) {}

    public function create(WarehouseCreateRequest $request): JsonResponse
    {
        return DB::transaction(function () use ($request) {
            $data = $this->sharedService->convertCamelToSnake($request->validated());
            $this->warehouseService->create($data);

            return response()->json(['message' => 'Warehouse created successfully.'], 201);
        });
    }

    public function update(WarehouseUpdateRequest $request, Warehouse $warehouse): JsonResponse
    {
        return DB::transaction(function () use ($request, $warehouse) {
            $this->warehouseService->validate($warehouse, 'Warehouse');

            $data = $this->sharedService->convertCamelToSnake($request->validated());
            $this->warehouseService->update($warehouse, $data);

            return response()->json(['message' => 'Warehouse updated successfully.']);
        });
    }

    public function delete(Warehouse $warehouse): JsonResponse
    {
        return DB::transaction(function () use ($warehouse): JsonResponse {
            $this->warehouseService->validate($warehouse, 'Warehouse');
            $this->warehouseService->delete($warehouse);

            return response()->json(['message' => 'Warehouse deleted successfully.']);
        });
    }

    public function get(Warehouse $warehouse): JsonResponse
    {
        $this->warehouseService->validate($warehouse, 'Warehouse');

        return response()->json(new WarehouseResource($warehouse));
    }

    public function getAll(GetAllRequest $request): JsonResponse
    {
        $tenantId = $request->query('tenant_id');
        $extendQuery = null;
        if ($tenantId !== null && $tenantId !== '') {
            $extendQuery = fn ($q) => $q->where('tenant_id', (int) $tenantId);
        }

        $query = $this->sharedService->query(
            request: $request,
            entityName: 'Administrations\\Warehouses',
            modelName: 'Warehouse',
            columnSearch: ['id', 'name'],
            extendQuery: $extendQuery,
        );

        return response()->json(new GetAllCollection(
            WarehouseResource::collection($query['collection']),
            $query['total'],
            $query['pages'],
        ));
    }
}
