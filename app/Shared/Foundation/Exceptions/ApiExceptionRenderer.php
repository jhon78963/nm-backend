<?php

namespace App\Shared\Foundation\Exceptions;

use App\Auth\Exceptions\InvalidTokenException;
use App\Auth\Exceptions\InvalidUserCredentialsException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use PDOException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

final class ApiExceptionRenderer
{
    private const INTERNAL_SERVER_MESSAGE = 'Error interno del servidor';

    public static function render(Throwable $exception, Request $request): ?JsonResponse
    {
        if (! $request->is('api/*')) {
            return null;
        }

        if ($exception instanceof ValidationException) {
            return self::validationResponse($exception);
        }

        return self::jsonError($exception, self::statusCode($exception));
    }

    public static function jsonError(
        Throwable $exception,
        int $status,
        array $payload = [],
    ): JsonResponse {
        if (! config('app.debug')) {
            report($exception);
        }

        $body = [
            'success' => false,
            'message' => self::clientMessage($exception, $status),
            'error' => self::errorCode($exception, $status),
        ];

        return response()->json(array_merge($body, $payload), $status);
    }

    public static function clientMessage(Throwable $exception, int $status): string
    {
        if (self::shouldExposeExceptionDetails()) {
            $message = trim($exception->getMessage());

            return $message !== '' ? $message : self::defaultMessage($status);
        }

        if ($exception instanceof ValidationException) {
            return collect($exception->errors())->flatten()->first()
                ?? 'Datos de la solicitud no válidos.';
        }

        if (self::mustHideInternalDetails($exception, $status)) {
            return self::INTERNAL_SERVER_MESSAGE;
        }

        if (self::allowsSpecificHttpMessage($exception, $status)) {
            $message = trim($exception->getMessage());

            if ($message !== '') {
                return $message;
            }
        }

        return self::defaultMessage($status);
    }

    public static function errorCode(Throwable $exception, int $status): string
    {
        if ($exception instanceof ValidationException) {
            return 'VALIDATION_ERROR';
        }

        if ($exception instanceof InvalidTokenException) {
            return 'INVALID_TOKEN';
        }

        if ($exception instanceof InvalidUserCredentialsException) {
            return 'UNAUTHENTICATED';
        }

        if ($exception instanceof UserWarehouseNotAssignedException) {
            return 'FORBIDDEN';
        }

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

        if ($exception instanceof AuthenticationException || $exception instanceof InvalidUserCredentialsException) {
            return Response::HTTP_UNAUTHORIZED;
        }

        if ($exception instanceof AuthorizationException || $exception instanceof UserWarehouseNotAssignedException) {
            return Response::HTTP_FORBIDDEN;
        }

        if ($exception instanceof InvalidTokenException) {
            return Response::HTTP_UNPROCESSABLE_ENTITY;
        }

        if ($exception instanceof ThrottleRequestsException) {
            return Response::HTTP_TOO_MANY_REQUESTS;
        }

        return Response::HTTP_INTERNAL_SERVER_ERROR;
    }

    private static function validationResponse(ValidationException $exception): JsonResponse
    {
        $message = collect($exception->errors())->flatten()->first()
            ?? 'Datos de la solicitud no válidos.';

        return response()->json([
            'success' => false,
            'message' => $message,
            'error' => 'VALIDATION_ERROR',
        ], $exception->status);
    }

    private static function shouldExposeExceptionDetails(): bool
    {
        return (bool) config('app.debug');
    }

    /**
     * En producción oculta detalles de motor, red o fallos internos (M7).
     */
    private static function mustHideInternalDetails(Throwable $exception, int $status): bool
    {
        if ($status < Response::HTTP_INTERNAL_SERVER_ERROR) {
            return false;
        }

        if ($exception instanceof QueryException || $exception instanceof PDOException) {
            return true;
        }

        if ($exception instanceof ConnectionException) {
            return true;
        }

        return true;
    }

    /**
     * Solo ValidationException (otro flujo) y Http 403/404 muestran mensaje específico en producción.
     */
    private static function allowsSpecificHttpMessage(Throwable $exception, int $status): bool
    {
        if (! in_array($status, [Response::HTTP_FORBIDDEN, Response::HTTP_NOT_FOUND], true)) {
            return false;
        }

        if ($exception instanceof AuthorizationException || $exception instanceof UserWarehouseNotAssignedException) {
            return true;
        }

        return $exception instanceof HttpExceptionInterface;
    }

    private static function defaultMessage(int $status): string
    {
        return match ($status) {
            Response::HTTP_BAD_REQUEST => 'La solicitud no es válida.',
            Response::HTTP_UNAUTHORIZED => 'No autenticado.',
            Response::HTTP_FORBIDDEN => 'Acceso denegado.',
            Response::HTTP_NOT_FOUND => 'Recurso no encontrado.',
            Response::HTTP_METHOD_NOT_ALLOWED => 'Método no permitido.',
            Response::HTTP_UNPROCESSABLE_ENTITY => 'No se pudo procesar la solicitud.',
            Response::HTTP_TOO_MANY_REQUESTS => 'Demasiadas peticiones. Inténtalo de nuevo en un momento.',
            default => $status >= Response::HTTP_INTERNAL_SERVER_ERROR
                ? self::INTERNAL_SERVER_MESSAGE
                : 'No se pudo completar la solicitud.',
        };
    }
}
