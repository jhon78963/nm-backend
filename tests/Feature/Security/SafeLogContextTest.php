<?php

use App\Shared\Foundation\Support\SafeLogContext;

it('no incluye el throwable completo en producción', function () {
    config(['app.env' => 'production']);

    $e = new RuntimeException('fallo interno', 0, new Exception('causa'));
    $context = SafeLogContext::fromThrowable($e, ['sale_id' => 1]);

    expect($context)->toHaveKeys(['sale_id', 'message', 'exception_class'])
        ->and($context)->not->toHaveKey('exception')
        ->and($context['message'])->toBe('fallo interno');
});

it('incluye el throwable en entornos no productivos', function () {
    config(['app.env' => 'local']);

    $e = new RuntimeException('detalle');
    $context = SafeLogContext::fromThrowable($e);

    expect($context)->toHaveKey('exception')
        ->and($context['exception'])->toBe($e);
});
