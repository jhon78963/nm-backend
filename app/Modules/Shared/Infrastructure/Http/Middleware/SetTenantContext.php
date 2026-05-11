<?php

declare(strict_types=1);

namespace App\Modules\Shared\Infrastructure\Http\Middleware;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Shared\Domain\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use Symfony\Component\HttpFoundation\Response;

final class SetTenantContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('sanctum')->user();

        if (! $user instanceof User) {
            $candidate = Auth::guard('web')->user();
            $user = $candidate instanceof User ? $candidate : null;
        }

        if ($user instanceof User) {
            TenantContext::set((int) $user->tenant_id);
            Context::add('tenant_id', $user->tenant_id);

            return $next($request);
        }

        TenantContext::forget();
        Context::forget('tenant_id');

        return $next($request);
    }
}
