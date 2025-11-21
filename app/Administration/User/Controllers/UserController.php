<?php

namespace App\Administration\User\Controllers;

use App\Shared\Foundation\Controllers\Controller;
use App\Shared\Foundation\Requests\GetAllRequest;
use App\Shared\Foundation\Resources\GetAllCollection;
use App\Shared\Foundation\Services\SharedService;
use App\Administration\User\Models\User;
use App\Administration\User\Requests\UserCreateRequest;
use App\Administration\User\Requests\UserUpdateRequest;
use App\Administration\User\Resources\UserResource;
use App\Administration\User\Services\UserService;
use Arr;
use Illuminate\Http\JsonResponse;
use DB;
use Hash;

class UserController extends Controller
{
    protected SharedService $sharedService;
    protected UserService $userService;

    public function __construct(
        SharedService $sharedService,
        UserService $userService,
    ) {
        $this->sharedService = $sharedService;
        $this->userService = $userService;
    }
    public function create(UserCreateRequest $request): JsonResponse
    {
        DB::beginTransaction();
        try {
            [$emailExists, $usernameExists] = $this->userService->checkUser(
                $request->email,
                $request->username,
            );

            if ($errorResponse = $this->generateErrorResponse(
                $emailExists,
                $usernameExists)
            ) {
                return response()->json($errorResponse);
            }

            $newUser = $this->prepareNewUserData(
                $request->validated(),
            );
            $this->userService->create($newUser);
            DB::commit();
            return response()->json(['message' => 'User created.'], 201);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(['error' =>  $e->getMessage()]);
        }
    }

    public function delete(User $user): JsonResponse {
        DB::beginTransaction();
        try {
            $userValidated = $this->userService->validate($user, 'User');
            $this->userService->delete($userValidated);
            DB::commit();
            return response()->json(['message' => 'User deleted.']);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(['error' =>  $e->getMessage()]);
        }
    }

    public function get(User $user): JsonResponse
    {
        $userValidated = $this->userService->validate($user, 'User');
        return response()->json(new UserResource($userValidated));
    }

    public function getAll(GetAllRequest $request): JsonResponse
    {
        $query = $this->sharedService->query(
            $request,
            'Administration\\User',
            'User',
            ['username', 'email', 'name', 'surname']
        );

        return response()->json(new GetAllCollection(
            UserResource::collection(resource: $query['collection']),
            $query['total'],
            $query['pages'],
        ));
    }

    public function update(UserUpdateRequest $request, User $user): JsonResponse
    {
        DB::beginTransaction();
        try {
            $userValidated = $this->userService->validate($user, 'User');
            $editUser = $this->prepareNewUserData(
                $request->validated(),
            );
            $editUser = Arr::except($editUser, ['password']);
            $this->userService->update($userValidated, $editUser);
            DB::commit();
            return response()->json(['message' => 'User updated.']);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(['error' =>  $e->getMessage()]);
        }
    }

    private function generateErrorResponse(bool $emailExists, bool $usernameExists): ?array
    {
        $errors = [];

        if ($emailExists) {
            $errors['email'] = 'El email ya existe.';
        }

        if ($usernameExists) {
            $errors['username'] = 'El username ya existe.';
        }

        if (!empty($errors)) {
            return [
                'status' => 'error',
                'message' => 'El email y/o username ya existen.',
                'errors' => $errors
            ];
        }

        return null;
    }

    private function prepareNewUserData(array $validatedData): array
    {
        $userData = array_merge(
            $validatedData,
            [
                'password' => Hash::make('password'),
            ],
        );
        return $this->sharedService->convertCamelToSnake($userData);
    }
}
