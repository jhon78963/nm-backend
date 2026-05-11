<?php

declare(strict_types=1);

namespace App\Modules\Shared\Infrastructure\Auditing;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use OwenIt\Auditing\Contracts\UserResolver as UserResolverContract;

/**
 * Resolves the acting user for audit metadata. Guard order comes from config/audit.php (Sanctum before web).
 */
final class AuditUserResolver implements UserResolverContract
{
    public static function resolve(): ?Authenticatable
    {
        /** @var list<string> $guards */
        $guards = Config::get('audit.user.guards', ['web']);

        foreach ($guards as $guard) {
            try {
                if (Auth::guard($guard)->check()) {
                    return Auth::guard($guard)->user();
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }
}
