<?php

namespace App\Auth\Controllers;

use App\Auth\Exceptions\InvalidTokenException;
use App\Auth\Requests\ChangePasswordRequest;
use App\Auth\Requests\LoginRequest;
use App\Auth\Requests\UpdateMeRequest;
use App\Administration\User\Models\User;
use App\Auth\Resources\MeResource;
use App\Auth\Services\AuthService;
use App\Shared\Foundation\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Auth;
use Symfony\Component\HttpFoundation\Cookie;

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

        return $this->withAuthCookies(
            response()->json(new MeResource($user)),
            $tokens,
        );
    }

    public function refresh(): JsonResponse
    {
        $refreshTokenPlain = request()->cookie('refresh_token');

        if (! is_string($refreshTokenPlain) || $refreshTokenPlain === '') {
            return response()->json(['message' => ['Invalid token.']], 401);
        }

        try {
            $accessTokenPlain = request()->cookie('access_token');
            $tokens = $this->authService->refreshFromCookies(
                is_string($accessTokenPlain) ? $accessTokenPlain : null,
                $refreshTokenPlain,
            );
        } catch (InvalidTokenException) {
            return response()->json(['message' => ['Invalid token.']], 401);
        }

        return $this->withAuthCookies(
            response()->json(['message' => 'Token refreshed successfully']),
            $tokens,
        );
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
        $user = $request->user();

        $this->authService->changePassword(
            $user,
            $request->validated('password'),
        );

        $user->refresh();

        return response()->json(new MeResource($user));
    }

    public function logout(): JsonResponse
    {
        $user = Auth::user();

        if ($user !== null) {
            $this->authService->revokeAllTokens($user);
        }

        return response()
            ->json(['message' => 'Logout successfully'])
            ->withoutCookie($this->clearAuthCookie('access_token'))
            ->withoutCookie($this->clearAuthCookie('refresh_token'));
    }

    private function withAuthCookies(JsonResponse $response, array $tokens): JsonResponse
    {
        return $response
            ->cookie($this->accessTokenCookie(
                $tokens['token'],
                (int) config('sanctum.access_token_expiration'),
            ))
            ->cookie($this->refreshTokenCookie(
                $tokens['refreshToken'],
                (int) config('sanctum.refresh_token_expiration'),
            ));
    }

    private function accessTokenCookie(string $value, int $minutes): Cookie
    {
        return $this->authCookie('access_token', $value, $minutes);
    }

    private function refreshTokenCookie(string $value, int $minutes): Cookie
    {
        return $this->authCookie('refresh_token', $value, $minutes);
    }

    private function authCookie(string $name, string $value, int $minutes): Cookie
    {
        return cookie(
            $name,
            $value,
            $minutes,
            '/',
            null,
            config('app.env') !== 'local',
            true,
            false,
            'Lax',
        );
    }

    private function clearAuthCookie(string $name): Cookie
    {
        return $this->authCookie($name, '', -1);
    }
}
