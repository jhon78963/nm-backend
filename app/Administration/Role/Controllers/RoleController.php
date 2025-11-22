<?php

namespace App\Administration\Role\Controllers;

use App\Administration\Role\Models\Role;
use App\Administration\Role\Requests\RoleCreateRequest;
use App\Administration\Role\Requests\RoleUpdateRequest;
use App\Administration\Role\Resources\RoleResource;
use App\Administration\Role\Services\RoleService;
use App\Shared\Foundation\Controllers\Controller;
use App\Shared\Foundation\Requests\GetAllRequest;
use App\Shared\Foundation\Resources\GetAllCollection;
use App\Shared\Foundation\Services\SharedService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class RoleController extends Controller
{
    public function __construct(
        protected RoleService $roleService,
        protected SharedService $sharedService
    ) {}

    public function create(RoleCreateRequest $request): JsonResponse
    {
        return DB::transaction(function () use ($request) {
            $data = $this->sharedService->convertCamelToSnake($request->validated());
            $this->roleService->create($data);

            return response()->json(['message' => 'Role created.'], 201);
        });
    }

    public function update(RoleUpdateRequest $request, Role $role): JsonResponse
    {
        return DB::transaction(function () use ($request, $role): JsonResponse {
            $this->roleService->validate($role, 'Role');
            $data = $this->sharedService->convertCamelToSnake($request->validated());
            $this->roleService->update($role, $data);

            return response()->json(['message' => 'Role updated.'], 200);
        });
    }

    public function delete(Role $role): JsonResponse
    {
        return DB::transaction(function () use ($role) {
            $this->roleService->validate($role, 'Role');
            $this->roleService->delete($role);

            return response()->json(['message' => 'Role deleted.'], 200);
        });
    }

    public function get(Role $role): JsonResponse
    {
        $this->roleService->validate($role, 'Role');

        return response()->json(new RoleResource($role));
    }

    public function getAll(GetAllRequest $request): JsonResponse
    {
        $query = $this->sharedService->query(
            request:      $request,
            entityName:   'Administration\\Role',
            modelName:    'Role',
            columnSearch: ['id', 'name']
        );

        return response()->json(new GetAllCollection(
            RoleResource::collection($query['collection']),
            $query['total'],
            $query['pages']
        ));
    }
}
