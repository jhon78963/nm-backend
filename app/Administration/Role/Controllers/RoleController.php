<?php

namespace App\Administration\Role\Controllers;

use App\Administration\Role\Models\Role;
use App\Administration\Role\Requests\RoleCreateRequest;
use App\Administration\Role\Requests\RoleUpdateRequest;
use App\Administration\Role\Resources\RoleResource;
use App\Administration\Role\Services\RoleService;
use App\Shared\Controllers\Controller;
use App\Shared\Requests\GetAllRequest;
use App\Shared\Resources\GetAllCollection;
use App\Shared\Services\SharedService;
use Illuminate\Http\JsonResponse;
use DB;

class RoleController extends Controller
{

    protected RoleService $roleService;
    protected SharedService $sharedService;

    public function __construct(RoleService $roleService, SharedService $sharedService)
    {
        $this->roleService = $roleService;
        $this->sharedService = $sharedService;
    }

    public function create(RoleCreateRequest $request): JsonResponse
    {
        DB::beginTransaction();
        try {
            $newRole = $this->sharedService->convertCamelToSnake(
                data: $request->validated()
            );
            $this->roleService->create(
                newRole: $newRole
            );
            DB::commit();
            return response()->json(
                data: ['message' => 'Role created.'],
                status: 201,
            );
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(
                data: ['error' =>  $e->getMessage()],
                status: 500,
            );
        }
    }

    public function delete(Role $role): JsonResponse
    {
        DB::beginTransaction();
        try {
            $roleValidated = $this->roleService->validate(
                role: $role,
                modelName: 'Role'
            );
            $this->roleService->delete(
                role: $roleValidated
            );
            DB::commit();
            return response()->json(
                data: ['message' => 'Role deleted.'],
                status: 204,
            );
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(
                data: ['error' =>  $e->getMessage()],
                status: 500,
            );
        }
    }

    public function get(Role $role): JsonResponse
    {
        $roleValidated = $this->roleService->validate(
            role: $role,
            modelName: 'Role'
        );
        return response()->json(
            data: new RoleResource(
                resource: $roleValidated,
            ),
        );
    }

    public function getAll(GetAllRequest $request): JsonResponse
    {
        $query = $this->sharedService->query(
            request: $request,
            entityName: 'Administration\\Role',
            modelName: 'Role',
            columnSearch: ['id', 'name']
        );

        return response()->json(new GetAllCollection(
            resource: RoleResource::collection(resource: $query['collection']),
            total: $query['total'],
            pages: $query['pages'],
        ));
    }

    public function update(RoleUpdateRequest $request, Role $role): JsonResponse
    {
        DB::beginTransaction();
        try {
            $editRole = $this->sharedService->convertCamelToSnake(
                data: $request->validated(),
            );
            $roleValidated = $this->roleService->validate(
                role: $role,
                modelName: 'Role',
            );
            $this->roleService->update(
                role: $roleValidated,
                editRole: $editRole,
            );
            DB::commit();
            return response()->json(
                data: ['message' => 'Role updated.'],
                status: 200,
            );
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(
                data: ['error' =>  $e->getMessage()],
                status: 500,
            );
        }
    }
}
