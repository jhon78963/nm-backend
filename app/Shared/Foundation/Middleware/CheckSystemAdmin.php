<?php

namespace App\Shared\Foundation\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Permite el paso únicamente al tenant 1 (SaaS Provider / System Admin).
 *
 * Registro en bootstrap/app.php:
 *   'system.admin' => CheckSystemAdmin::class
 *
 * Uso en rutas:
 *   Route::middleware(['auth:sanctum', 'system.admin'])->group(...)
 */
class CheckSystemAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], Response::HTTP_UNAUTHORIZED);
        }

        if ((int) $user->tenant_id !== 1) {
            return response()->json(
                ['message' => 'Acceso denegado. Esta sección es exclusiva del proveedor SaaS.'],
                Response::HTTP_FORBIDDEN
            );
        }

        return $next($request);
    }
}
