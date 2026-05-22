<?php

namespace App\Auth\Services;

use App\Administration\User\Models\User;
use App\Auth\Enums\TokenAbility;
use App\Auth\Exceptions\InvalidTokenException;
use App\Auth\Exceptions\InvalidUserCredentialsException;
use App\Auth\Models\PersonalAccessToken;
use App\Auth\Requests\UpdateMeRequest;
use Carbon\Carbon;
use Hash;

class AuthService
{
    public function validateUser(string $password, ?User $user): User
    {
        if ($user === null || ! Hash::check($password, $user->password)) {
            throw new InvalidUserCredentialsException();
        }

        return $user;
    }

    public function login(string $username, string $password): array
    {
        $user = User::query()->where('username', $username)->first();
        $validatedUser = $this->validateUser($password, $user);

        return array_merge(
            $this->createTokens($validatedUser, revokeExistingTokens: true),
            ['mustChangePassword' => (bool) $validatedUser->must_change_password],
        );
    }

    public function updateMe(UpdateMeRequest $request): void {
        $request->user()->fill(
            $request->only(['username', 'email', 'name', 'surname'])
        )->save();
    }

    public function changePassword(User $user, string $newPassword): void
    {
        $user->password = $newPassword;
        $user->must_change_password = false;
        $user->save();
    }

    public function createTokens(User $user, bool $revokeExistingTokens = false): array
    {
        if ($revokeExistingTokens) {
            $user->tokens()->delete();
        }

        $accessToken = $user->createToken(
            'access_token',
            [TokenAbility::ACCESS_API->value],
            Carbon::now()->addMinutes(config('sanctum.access_token_expiration'))
        )->plainTextToken;

        $refreshToken = $user->createToken(
            'refresh_token',
            [TokenAbility::ISSUE_ACCESS_TOKEN->value],
            Carbon::now()->addMinutes(config('sanctum.refresh_token_expiration'))
        )->plainTextToken;

        return $this->generateTokenResponse($accessToken, $refreshToken);
    }

    public function revokeAllTokens(User $user): void
    {
        $user->tokens()->delete();
    }

    public function refreshFromCookies(?string $accessTokenPlain, string $refreshTokenPlain): array
    {
        $refreshToken = PersonalAccessToken::findToken($refreshTokenPlain);

        if ($refreshToken === null || ! $refreshToken->can(TokenAbility::ISSUE_ACCESS_TOKEN->value)) {
            throw new InvalidTokenException();
        }

        if ($refreshToken->expires_at !== null && $refreshToken->expires_at->isPast()) {
            throw new InvalidTokenException();
        }

        $user = User::find($refreshToken->tokenable_id);

        if ($user === null) {
            throw new InvalidTokenException();
        }

        if (is_string($accessTokenPlain) && $accessTokenPlain !== '') {
            $accessToken = PersonalAccessToken::findToken($accessTokenPlain);

            if ($accessToken !== null && $accessToken->tokenable_id === $user->id) {
                $accessToken->delete();
            }
        }

        $refreshToken->delete();

        return $this->createTokens($user);
    }

    public function generateTokenResponse(string $accessToken, string $refreshToken): array
    {
        return [
            'token' => $accessToken,
            'refreshToken' => $refreshToken,
            'expirationToken'=> $this->calculateExpirationInMilliseconds(
                config('sanctum.access_token_expiration')
            ),
            'expirationRefreshToken'=> $this->calculateExpirationInMilliseconds(
                config('sanctum.refresh_token_expiration')
            ),
        ];
    }

    private function calculateExpirationInMilliseconds(int $expirationInMinutes): int
    {
        return Carbon::now()->addMinutes($expirationInMinutes)->getTimestampMs();
    }
}
