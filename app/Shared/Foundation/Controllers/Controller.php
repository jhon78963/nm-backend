<?php

namespace App\Shared\Foundation\Controllers;

use App\Shared\Foundation\Exceptions\ApiExceptionRenderer;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Throwable;

abstract class Controller
{
    use AuthorizesRequests;
    protected function apiErrorResponse(
        Throwable $exception,
        int $status,
        array $payload = [],
    ): JsonResponse {
        return ApiExceptionRenderer::jsonError($exception, $status, $payload);
    }
}
