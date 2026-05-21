<?php

namespace App\Auth\Controllers;

use App\Auth\Requests\ChangePasswordRequest;
use App\Auth\Requests\LoginRequest;
use App\Auth\Requests\RefreshTokenRequest;
use App\Auth\Requests\UpdateMeRequest;
use App\Administration\User\Models\User;
use App\Auth\Resources\MeResource;
use App\Auth\Services\AuthService;
use App\Shared\Foundation\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Auth;

class AuthController extends Controller
{
    protected AuthService $authService;

    public function __construct(AuthService $authService)
    {
        $this->authService = $authService;
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $tokens = $this->authService->login(
            $request->validated('username'),
            $request->validated('password'),
        );

        $user = User::query()
            ->where('username', $request->validated('username'))
            ->firstOrFail();

        return response()
            ->json(new MeResource($user))
            ->cookie($this->accessTokenCookie($tokens['token'], 1440));
    }

    public function refreshToken(RefreshTokenRequest $request): JsonResponse
    {
        $userAccess = $this->authService->validateTokens($request);
        ['user' => $user, 'accessToken' => $accessToken, 'refreshToken' => $refreshToken] = $userAccess;
        $this->authService->deleteToken($user, $accessToken, $refreshToken);
        $getTokens = $this->authService->createTokens($user);
        return response()->json($getTokens);
    }

    public function getMe(): JsonResponse {
        return response()->json(new MeResource(Auth::user()));
    }

    public function updateMe(UpdateMeRequest $request): JsonResponse {
        $this->authService->updateMe($request);
        return response()->json(['message' => 'User updated successfully']);
    }

    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $this->authService->changePassword(
            $request->user(),
            $request->validated('password'),
        );

        return response()->json(['message' => 'Password changed successfully']);
    }

    public function logout(): JsonResponse
    {
        $user = Auth::user();

        if ($user !== null) {
            $this->authService->revokeAllTokens($user);
        }

        return response()
            ->json(['message' => 'Logout successfully'])
            ->withoutCookie($this->accessTokenCookie('', -1));
    }

    private function accessTokenCookie(string $value, int $minutes): \Symfony\Component\HttpFoundation\Cookie
    {
        return cookie(
            'access_token',
            $value,
            $minutes,
            '/',
            null,
            env('APP_ENV') !== 'local',
            true,
            false,
            'Lax',
        );
    }
}
