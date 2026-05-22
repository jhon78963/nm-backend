<?php

namespace App\Auth\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ForcePasswordChange
{
    /**
     * Rutas permitidas cuando el usuario debe cambiar su contraseña.
     * auth/me es necesario para hidratar la sesión en el frontend.
     *
     * @var list<string>
     */
    private const ALLOWED_ROUTE_PATTERNS = [
        'api/auth/logout',
        'api/auth/change-password',
        'api/auth/me',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->must_change_password) {
            return $next($request);
        }

        foreach (self::ALLOWED_ROUTE_PATTERNS as $pattern) {
            if ($request->is($pattern)) {
                return $next($request);
            }
        }

        return response()->json([
            'success' => false,
            'message' => 'Debe cambiar su contraseña antes de continuar.',
            'error' => 'PASSWORD_CHANGE_REQUIRED',
        ], 403);
    }
}
