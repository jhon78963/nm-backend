<?php

namespace App\Administration\User\Controllers;

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
    // Constructor Property Promotion
    public function __construct(
        protected SharedService $sharedService,
        protected UserService $userService,
    ) {}

    public function create(UserCreateRequest $request): JsonResponse
    {
        return DB::transaction(function () use ($request): JsonResponse {
            $data = $this->sharedService->convertCamelToSnake($request->validated());
            $data['password'] = Hash::make('password');
            $this->userService->create($data);

            return response()->json(['message' => 'User created successfully.'], 201);
        });
    }

    public function update(UserUpdateRequest $request, User $user): JsonResponse
    {
        return DB::transaction(function () use ($request, $user): JsonResponse {
            $this->userService->validate($user, 'User');
            $data = $this->sharedService->convertCamelToSnake($request->validated());
            $data = Arr::except($data, ['password', 'username']);
            $this->userService->update($user, $data);

            return response()->json(['message' => 'User updated successfully.']);
        });
    }

    public function delete(User $user): JsonResponse
    {
        return DB::transaction(function () use ($user): JsonResponse {
            $this->userService->validate($user, 'User');
            $this->userService->delete($user);

            return response()->json(['message' => 'User deleted successfully.']);
        });
    }

    public function get(User $user): JsonResponse
    {
        $this->userService->validate($user, 'User');
        return response()->json(new UserResource($user));
    }

    public function getAll(GetAllRequest $request): JsonResponse
    {
        $query = $this->sharedService->query(
            request:      $request,
            entityName:   'Administration\\User',
            modelName:    'User',
            columnSearch: ['username', 'email', 'name', 'surname']
        );

        return response()->json(new GetAllCollection(
            UserResource::collection($query['collection']),
            $query['total'],
            $query['pages']
        ));
    }
}
