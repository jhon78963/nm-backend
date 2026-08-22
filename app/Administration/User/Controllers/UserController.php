<?php

namespace App\Administration\User\Controllers;

use App\Administration\User\Support\SuperAdminRole;
use App\Administration\User\Models\User;
use App\Administration\User\Requests\UserCreateRequest;
use App\Administration\User\Requests\UserResetPasswordRequest;
use App\Administration\User\Requests\UserUpdateRequest;
use App\Administration\User\Resources\UserResource;
use App\Administration\User\Services\UserService;
use App\Shared\Foundation\Controllers\Controller;
use App\Shared\Foundation\Requests\GetAllRequest;
use App\Shared\Foundation\Resources\GetAllCollection;
use App\Shared\Foundation\Services\SharedService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    public function __construct(
        protected SharedService $sharedService,
        protected UserService $userService,
    ) {}

    public function create(UserCreateRequest $request): JsonResponse
    {
        return DB::transaction(function () use ($request): JsonResponse {
            $data = $this->sharedService->convertCamelToSnake($request->validated());
            $roleNames = Arr::pull($data, 'role_names', []);
            $password = (string) Arr::pull($data, 'password');
            $tenantId = Arr::pull($data, 'tenant_id');
            $warehouseId = Arr::pull($data, 'warehouse_id');
            Arr::forget($data, 'password_confirmation');

            $user = new User;
            $user->fill($data);
            $user->tenant_id = $tenantId;
            $user->warehouse_id = $warehouseId;
            $user->password = Hash::make($password);
            $user->save();
            $user->syncRoles(is_array($roleNames) ? $roleNames : []);

            return response()->json(['message' => 'User created successfully.'], 201);
        });
    }

    public function update(UserUpdateRequest $request, User $user): JsonResponse
    {
        return DB::transaction(function () use ($request, $user): JsonResponse {
            $this->assertActorCanAccessUser($user);
            $this->userService->validate($user, 'User');
            $data = $this->sharedService->convertCamelToSnake($request->validated());
            $roleNames = Arr::pull($data, 'role_names');
            $tenantId = Arr::pull($data, 'tenant_id');
            $warehouseId = Arr::pull($data, 'warehouse_id');
            $data = Arr::except($data, ['password']);
            $user->fill($data);
            if ($tenantId !== null) {
                $user->tenant_id = $tenantId;
            }
            if ($warehouseId !== null) {
                $user->warehouse_id = $warehouseId;
            }
            $user->save();
            if ($roleNames !== null) {
                $user->syncRoles(is_array($roleNames) ? $roleNames : []);
            }

            return response()->json(['message' => 'User updated successfully.']);
        });
    }

    public function resetPassword(UserResetPasswordRequest $request, User $user): JsonResponse
    {
        return DB::transaction(function () use ($request, $user): JsonResponse {
            $this->assertActorCanAccessUser($user);
            $this->userService->validate($user, 'User');

            $user->password = $request->validated('password');
            $user->must_change_password = true;
            $user->save();
            $user->tokens()->delete();

            return response()->json(['message' => 'Password reset successfully.']);
        });
    }

    public function delete(User $user): JsonResponse
    {
        return DB::transaction(function () use ($user): JsonResponse {
            $this->assertActorCanAccessUser($user);

            if ($user->is_deleted) {
                return response()->json(['message' => 'El usuario ya está deshabilitado.'], 422);
            }

            if ((int) auth()->id() === (int) $user->id) {
                abort(422, 'No puedes deshabilitar tu propia cuenta.');
            }

            $user->tokens()->delete();
            $this->userService->delete($user);

            return response()->json(['message' => 'Usuario deshabilitado correctamente.']);
        });
    }

    public function get(User $user): JsonResponse
    {
        $this->assertActorCanAccessUser($user);
        $this->userService->validate($user, 'User');
        $user->load(['tenant:id,name', 'warehouse:id,name,tenant_id']);

        return response()->json(new UserResource($user));
    }

    public function getAll(GetAllRequest $request): JsonResponse
    {
        $actor = auth()->user();
        $tenantFilter = $request->query('tenant_id', $request->query('tenantId'));
        $warehouseFilter = $request->query('warehouse_id', $request->query('warehouseId'));

        $extendQuery = function ($query) use ($actor, $tenantFilter, $warehouseFilter): void {
            $query->with(['tenant:id,name', 'warehouse:id,name,tenant_id']);

            if ($actor !== null && ! $this->actorIsSuperAdmin($actor)) {
                $query->where('tenant_id', (int) $actor->tenant_id);
            } elseif ($tenantFilter !== null && $tenantFilter !== '') {
                $query->where('tenant_id', (int) $tenantFilter);
            }

            if ($warehouseFilter !== null && $warehouseFilter !== '') {
                $query->where('warehouse_id', (int) $warehouseFilter);
            }
        };

        $query = $this->sharedService->query(
            request: $request,
            entityName: 'Administration\\User',
            modelName: 'User',
            columnSearch: ['username', 'email', 'name', 'surname'],
            extendQuery: $extendQuery,
            orderBy: 'is_deleted',
            orderDir: 'asc',
            includeDeleted: true,
        );

        return response()->json(new GetAllCollection(
            UserResource::collection($query['collection']),
            $query['total'],
            $query['pages']
        ));
    }

    private function assertActorCanAccessUser(User $user): void
    {
        $actor = auth()->user();

        if ($actor === null) {
            abort(403, 'Forbidden');
        }

        if ($this->actorIsSuperAdmin($actor)) {
            return;
        }

        if ((int) $user->tenant_id !== (int) $actor->tenant_id) {
            abort(403, 'No tiene permiso para gestionar usuarios de otro tenant.');
        }
    }

    private function actorIsSuperAdmin(User $actor): bool
    {
        return method_exists($actor, 'hasRole')
            && $actor->hasRole(SuperAdminRole::NAME);
    }
}
