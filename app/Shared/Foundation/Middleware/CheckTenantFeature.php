<?php

namespace App\Shared\Foundation\Middleware;

use App\Administration\Tenant\Enums\TenantFeature;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware de Feature Toggling.
 *
 * Bloquea peticiones a rutas específicas si el tenant del usuario autenticado
 * no tiene ese módulo comercial activo.
 *
 * Registro en bootstrap/app.php:
 *   'check.feature' => CheckTenantFeature::class
 *
 * Uso en rutas (uno o varios módulos requeridos):
 *   Route::middleware('check.feature:electronic_billing')->...
 *   Route::middleware('check.feature:ecommerce,multi_branch')->...
 *
 * Uso con el enum (más seguro en código PHP):
 *   ->middleware('check.feature:' . TenantFeature::Ecommerce->value)
 *
 * Comportamiento:
 *  - Si el usuario no está autenticado o no tiene tenant: 403.
 *  - Si el tenant no tiene TODOS los módulos indicados: 403.
 *  - El rol 'Super Admin' omite el chequeo (tiene acceso a todo).
 */
class CheckTenantFeature
{
    public function handle(Request $request, Closure $next, string ...$features): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], Response::HTTP_UNAUTHORIZED);
        }

        // Super Admin tiene acceso sin restricciones de módulo.
        if (method_exists($user, 'hasRole') && $user->hasRole('Super Admin')) {
            return $next($request);
        }

        $tenant = $user->tenant;

        if (! $tenant) {
            return response()->json(
                ['message' => 'Tu cuenta no está asociada a ninguna organización.'],
                Response::HTTP_FORBIDDEN
            );
        }

        if (! $tenant->is_active) {
            return response()->json(
                ['message' => 'La organización está desactivada. Contacta con soporte.'],
                Response::HTTP_FORBIDDEN
            );
        }

        foreach ($features as $feature) {
            if (! $tenant->hasFeature($feature)) {
                $label = TenantFeature::tryFrom($feature)?->label() ?? $feature;

                return response()->json([
                    'message' => "Tu plan no incluye el módulo \"{$label}\". "
                               . 'Contacta con ventas para habilitarlo.',
                    'feature' => $feature,
                ], Response::HTTP_FORBIDDEN);
            }
        }

        return $next($request);
    }
}
