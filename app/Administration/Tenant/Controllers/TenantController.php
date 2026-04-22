<?php

namespace App\Administration\Tenant\Controllers;

use App\Administration\Tenant\Models\Tenant;
use App\Administration\Tenant\Requests\TenantCreateRequest;
use App\Administration\Tenant\Requests\TenantUpdateRequest;
use App\Administration\Tenant\Resources\TenantResource;
use App\Shared\Foundation\Controllers\Controller;
use App\Shared\Foundation\Requests\GetAllRequest;
use App\Shared\Foundation\Resources\GetAllCollection;
use App\Shared\Foundation\Services\SharedService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Cliente SaaS (empresa que usa el sistema). Las tiendas físicas son {@see \App\Inventory\Warehouse\Models\Warehouse}.
 */
class TenantController extends Controller
{
    public function __construct(
        protected SharedService $sharedService,
    ) {}

    public function create(TenantCreateRequest $request): JsonResponse
    {
        return DB::transaction(function () use ($request): JsonResponse {
            $data = $this->sharedService->convertCamelToSnake($request->validated());
            $tenant = Tenant::query()->create($data);

            return response()->json(new TenantResource($tenant), 201);
        });
    }

    public function update(TenantUpdateRequest $request, Tenant $tenant): JsonResponse
    {
        return DB::transaction(function () use ($request, $tenant): JsonResponse {
            $data = $this->sharedService->convertCamelToSnake($request->validated());
            $tenant->update($data);

            return response()->json(new TenantResource($tenant->fresh()));
        });
    }

    public function delete(Tenant $tenant): JsonResponse
    {
        return DB::transaction(function () use ($tenant): JsonResponse {
            if ($tenant->warehouses()->exists() || $tenant->users()->exists()) {
                return response()->json([
                    'message' => 'No se puede eliminar: hay tiendas o usuarios asociados a este cliente.',
                ], 422);
            }
            $tenant->delete();

            return response()->json(['message' => 'Cliente eliminado.']);
        });
    }

    public function get(Tenant $tenant): JsonResponse
    {
        return response()->json(new TenantResource($tenant));
    }

    public function getAll(GetAllRequest $request): JsonResponse
    {
        $query = Tenant::query()->orderBy('name');
        $limit = (int) $request->query('limit', 10);
        $page = (int) $request->query('page', 1);
        $search = (string) $request->query('search', '');

        if ($search !== '') {
            $query->where('name', 'ilike', '%'.$search.'%');
        }

        $total = $query->count();
        $pages = $total > 0 ? (int) ceil($total / $limit) : 0;
        $collection = $query->skip(max(0, ($page - 1) * $limit))->take($limit)->get();

        return response()->json(new GetAllCollection(
            TenantResource::collection($collection),
            $total,
            $pages,
        ));
    }
}
