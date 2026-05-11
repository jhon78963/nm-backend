<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Http\Controllers;

use App\Modules\Identity\Domain\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

final class AuthController
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email:rfc'],
            'password' => ['required', 'string'],
        ]);

        /** @var User|null $user */
        $user = User::query()->where('email', $credentials['email'])->first();

        if ($user === null || ! Hash::check($credentials['password'], $user->getAuthPassword())) {
            throw ValidationException::withMessages([
                'email' => [__('auth.failed')],
            ]);
        }

        return response()->json([
            'token' => $user->createToken('api')->plainTextToken,
        ]);
    }
}
