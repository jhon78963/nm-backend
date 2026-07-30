<?php

namespace App\Administration\Audit\Middleware;

use App\Administration\Audit\Services\UserActionLogService;
use App\Administration\Audit\Support\AuditHttpActionResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LogUserActivity
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $user = $request->user();

        if ($user === null || ! AuditHttpActionResolver::shouldLog($request)) {
            return $response;
        }

        UserActionLogService::logSafely(
            AuditHttpActionResolver::resolveAction($request),
            AuditHttpActionResolver::resolveDescription($request),
            AuditHttpActionResolver::resolveMetadata($request, $response->getStatusCode()),
            $user,
        );

        return $response;
    }
}
