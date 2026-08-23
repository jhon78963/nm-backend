<?php

namespace App\Inventory\Warehouse\Controllers;

use App\Administration\User\Models\User;
use App\Administration\User\Support\SuperAdminRole;
use App\Inventory\Warehouse\Models\Warehouse;
use App\Inventory\Warehouse\Requests\WarehouseCreateRequest;
use App\Inventory\Warehouse\Requests\WarehouseUpdateRequest;
use App\Inventory\Warehouse\Resources\WarehouseResource;
use App\Inventory\Warehouse\Services\WarehouseService;
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
            $this->assertActorCanAccessWarehouse($warehouse);
            $this->warehouseService->validate($warehouse, 'Warehouse');

            $data = $this->sharedService->convertCamelToSnake($request->validated());
            $this->warehouseService->update($warehouse, $data);

            return response()->json(['message' => 'Warehouse updated successfully.']);
        });
    }

    public function delete(Warehouse $warehouse): JsonResponse
    {
        return DB::transaction(function () use ($warehouse) {
            $this->assertActorCanAccessWarehouse($warehouse);
            $this->warehouseService->validate($warehouse, 'Warehouse');
            $this->warehouseService->delete($warehouse);

            return response()->json(['message' => 'Warehouse deleted successfully.']);
        });
    }

    public function get(Warehouse $warehouse): JsonResponse
    {
        $this->assertActorCanAccessWarehouse($warehouse);
        $this->warehouseService->validate($warehouse, 'Warehouse');

        return response()->json(new WarehouseResource($warehouse));
    }

    public function getAll(GetAllRequest $request): JsonResponse
    {
        $actor = auth()->user();
        $tenantFilter = $request->query('tenant_id', $request->query('tenantId'));

        $extendQuery = function ($query) use ($actor, $tenantFilter): void {
            $query->with('tenant:id,name');

            if ($actor !== null && ! $this->actorIsSuperAdmin($actor)) {
                $query->where('tenant_id', (int) $actor->tenant_id);

                return;
            }

            if ($tenantFilter !== null && $tenantFilter !== '') {
                $query->where('tenant_id', (int) $tenantFilter);
            }
        };

        $query = $this->sharedService->query(
            request: $request,
            entityName: 'Inventory\\Warehouse',
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

    private function assertActorCanAccessWarehouse(Warehouse $warehouse): void
    {
        $actor = auth()->user();

        if ($actor === null) {
            abort(403, 'Forbidden');
        }

        if ($this->actorIsSuperAdmin($actor)) {
            return;
        }

        if ((int) $warehouse->tenant_id !== (int) $actor->tenant_id) {
            abort(403, 'No tiene permiso para gestionar tiendas de otro tenant.');
        }
    }

    private function actorIsSuperAdmin(User $actor): bool
    {
        return method_exists($actor, 'hasRole')
            && $actor->hasRole(SuperAdminRole::NAME);
    }
}
