<?php

namespace App\Shared\Foundation\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

final class ApiExceptionRenderer
{
    public static function render(Throwable $exception, Request $request): ?JsonResponse
    {
        if (app()->environment('local') || ! $request->is('api/*')) {
            return null;
        }

        if ($exception instanceof ValidationException) {
            return null;
        }

        if (self::hasSafeAppRenderMethod($exception)) {
            $response = $exception->render();

            return $response instanceof JsonResponse ? $response : null;
        }

        return self::jsonError($exception, self::statusCode($exception));
    }

    public static function jsonError(
        Throwable $exception,
        int $status,
        array $payload = [],
    ): JsonResponse {
        if (! app()->environment('local')) {
            report($exception);
        }

        return response()->json(array_merge([
            'error' => self::errorCode($exception, $status),
            'message' => self::clientMessage($exception, $status),
        ], $payload), $status);
    }

    public static function clientMessage(Throwable $exception, int $status): string
    {
        if (app()->environment('local')) {
            $message = trim($exception->getMessage());

            return $message !== '' ? $message : self::defaultMessage($status);
        }

        if ($exception instanceof ValidationException) {
            return collect($exception->errors())->flatten()->first()
                ?? 'Datos de la solicitud no válidos.';
        }

        if (str_starts_with($exception::class, 'App\\')) {
            $message = trim($exception->getMessage());

            if ($message !== '') {
                return $message;
            }
        }

        return self::defaultMessage($status);
    }

    public static function errorCode(Throwable $exception, int $status): string
    {
        if ($exception instanceof ThrottleRequestsException) {
            return 'TOO_MANY_REQUESTS';
        }

        return match ($status) {
            Response::HTTP_BAD_REQUEST => 'BAD_REQUEST',
            Response::HTTP_UNAUTHORIZED => 'UNAUTHENTICATED',
            Response::HTTP_FORBIDDEN => 'FORBIDDEN',
            Response::HTTP_NOT_FOUND => 'NOT_FOUND',
            Response::HTTP_METHOD_NOT_ALLOWED => 'METHOD_NOT_ALLOWED',
            Response::HTTP_CONFLICT => 'CONFLICT',
            Response::HTTP_UNPROCESSABLE_ENTITY => 'UNPROCESSABLE_ENTITY',
            Response::HTTP_TOO_MANY_REQUESTS => 'TOO_MANY_REQUESTS',
            default => 'INTERNAL_SERVER_ERROR',
        };
    }

    public static function statusCode(Throwable $exception): int
    {
        if ($exception instanceof HttpExceptionInterface) {
            return $exception->getStatusCode();
        }

        if ($exception instanceof ModelNotFoundException || $exception instanceof NotFoundHttpException) {
            return Response::HTTP_NOT_FOUND;
        }

        if ($exception instanceof AuthenticationException) {
            return Response::HTTP_UNAUTHORIZED;
        }

        if ($exception instanceof AuthorizationException) {
            return Response::HTTP_FORBIDDEN;
        }

        if ($exception instanceof ThrottleRequestsException) {
            return Response::HTTP_TOO_MANY_REQUESTS;
        }

        return Response::HTTP_INTERNAL_SERVER_ERROR;
    }

    private static function hasSafeAppRenderMethod(Throwable $exception): bool
    {
        return str_starts_with($exception::class, 'App\\')
            && method_exists($exception, 'render');
    }

    private static function defaultMessage(int $status): string
    {
        return match ($status) {
            Response::HTTP_BAD_REQUEST => 'La solicitud no es válida.',
            Response::HTTP_UNAUTHORIZED => 'No autenticado.',
            Response::HTTP_FORBIDDEN => 'Acceso denegado.',
            Response::HTTP_NOT_FOUND => 'Recurso no encontrado.',
            Response::HTTP_METHOD_NOT_ALLOWED => 'Método no permitido.',
            Response::HTTP_TOO_MANY_REQUESTS => 'Demasiadas peticiones. Inténtalo de nuevo en un momento.',
            default => $status >= Response::HTTP_INTERNAL_SERVER_ERROR
                ? 'Ha ocurrido un error interno. Inténtalo nuevamente más tarde.'
                : 'No se pudo completar la solicitud.',
        };
    }
}
