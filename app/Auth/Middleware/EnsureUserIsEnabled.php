<?php

namespace App\Auth\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->is_deleted) {
            return $next($request);
        }

        $user->tokens()->delete();

        return response()->json([
            'success' => false,
            'message' => 'Tu cuenta está deshabilitada. Contacta al administrador.',
            'error' => 'ACCOUNT_DISABLED',
        ], 403);
    }
}
