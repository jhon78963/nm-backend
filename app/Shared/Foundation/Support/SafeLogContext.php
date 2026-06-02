<?php

namespace App\Shared\Foundation\Support;

use Throwable;

/**
 * Contexto de log seguro: en producción no incluye el objeto Throwable completo (evita stack traces).
 */
final class SafeLogContext
{
    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    public static function fromThrowable(Throwable $e, array $extra = []): array
    {
        $context = array_merge($extra, [
            'message' => $e->getMessage(),
            'exception_class' => $e::class,
        ]);

        if (config('app.env') !== 'production') {
            $context['exception'] = $e;
        }

        return $context;
    }
}
