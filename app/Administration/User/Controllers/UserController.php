<?php

namespace App\Administration\User\Controllers;

use App\Administration\User\Support\SuperAdminRole;
use App\Administration\User\Models\User;
use App\Administration\User\Requests\UserCreateRequest;
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
            $data = Arr::except($data, ['password', 'username']);
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

    public function delete(User $user): JsonResponse
    {
        return DB::transaction(function () use ($user): JsonResponse {
            $this->assertActorCanAccessUser($user);
            $this->userService->validate($user, 'User');
            $this->userService->delete($user);

            return response()->json(['message' => 'User deleted successfully.']);
        });
    }

    public function get(User $user): JsonResponse
    {
        $this->assertActorCanAccessUser($user);
        $this->userService->validate($user, 'User');

        return response()->json(new UserResource($user));
    }

    public function getAll(GetAllRequest $request): JsonResponse
    {
        $extendQuery = null;
        $actor = auth()->user();

        if ($actor !== null && ! $this->actorIsSuperAdmin($actor)) {
            $extendQuery = fn ($query) => $query->where('tenant_id', (int) $actor->tenant_id);
        }

        $query = $this->sharedService->query(
            request: $request,
            entityName: 'Administration\\User',
            modelName: 'User',
            columnSearch: ['username', 'email', 'name', 'surname'],
            extendQuery: $extendQuery,
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
