<?php

namespace App\Administration\Role\Controllers;

use App\Administration\Role\Requests\RoleStoreRequest;
use App\Administration\Role\Requests\RoleUpdateRequest;
use App\Administration\Role\Requests\SyncRolePermissionsRequest;
use App\Administration\Role\Resources\PermissionResource;
use App\Administration\Role\Resources\RoleResource;
use App\Shared\Foundation\Controllers\Controller;
use App\Shared\Foundation\Requests\GetAllRequest;
use App\Shared\Foundation\Resources\GetAllCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    public function getAll(GetAllRequest $request): JsonResponse
    {
        $limit = (int) $request->query('limit', 10);
        $page = (int) $request->query('page', 1);
        $search = (string) $request->query('search', '');

        $query = Role::query()->where('guard_name', 'web');

        if ($search !== '') {
            $query->where('name', 'ilike', '%'.$search.'%');
        }

        $total = $query->count();
        $pages = $total > 0 ? (int) ceil($total / $limit) : 0;

        $collection = $query->orderBy('name')
            ->skip(max(0, ($page - 1) * $limit))
            ->take($limit)
            ->get();

        return response()->json(new GetAllCollection(
            RoleResource::collection($collection),
            $total,
            $pages,
        ));
    }

    public function get(Role $role): JsonResponse
    {
        $role->load('permissions');

        return response()->json(new RoleResource($role));
    }

    public function create(RoleStoreRequest $request): JsonResponse
    {
        return DB::transaction(function () use ($request): JsonResponse {
            $role = Role::create([
                'name' => $request->validated('name'),
                'guard_name' => 'web',
            ]);
            $permissions = $request->validated('permissions');
            if (is_array($permissions) && $permissions !== []) {
                $role->syncPermissions($permissions);
            }
            $role->load('permissions');
            app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

            return response()->json(new RoleResource($role), 201);
        });
    }

    public function update(RoleUpdateRequest $request, Role $role): JsonResponse
    {
        return DB::transaction(function () use ($request, $role): JsonResponse {
            $validated = $request->validated();
            if (array_key_exists('name', $validated)) {
                $role->update(['name' => $validated['name']]);
            }
            if (array_key_exists('permissions', $validated)) {
                $role->syncPermissions($validated['permissions']);
            }
            app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

            return response()->json(new RoleResource($role->fresh(['permissions'])));
        });
    }

    public function delete(Role $role): JsonResponse
    {
        return DB::transaction(function () use ($role): JsonResponse {
            $role->delete();
            app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

            return response()->json(['message' => 'Rol eliminado.']);
        });
    }

    public function syncPermissions(SyncRolePermissionsRequest $request, Role $role): JsonResponse
    {
        return DB::transaction(function () use ($request, $role): JsonResponse {
            $names = $request->validated('permissions');
            $role->syncPermissions($names);
            $role->load('permissions');
            app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

            return response()->json([
                'message' => 'Permisos sincronizados.',
                'role' => new RoleResource($role),
            ]);
        });
    }

    public function permissionsIndex(): JsonResponse
    {
        $permissions = Permission::query()
            ->where('guard_name', 'web')
            ->orderBy('name')
            ->get();

        return response()->json(PermissionResource::collection($permissions));
    }
}
