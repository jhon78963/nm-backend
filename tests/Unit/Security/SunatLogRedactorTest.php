<?php

use App\Finance\Sale\Support\SunatLogRedactor;

it('enmascara document_number y nombres en payloads', function () {
    $redacted = SunatLogRedactor::redactPayload([
        'document_number' => '20123456789',
        'name' => 'Juan Perez Garcia',
        'nested' => [
            'dni' => '12345678',
        ],
    ]);

    expect($redacted['document_number'])->toBe('20*******89')
        ->and($redacted['name'])->toBe('J***')
        ->and($redacted['nested']['dni'])->toBe('12****8');
});

it('enmascara RUC y DNI embebidos en campos no-PII', function () {
    $redacted = SunatLogRedactor::redactPayload([
        'note' => 'Receptor 20123456789 con DNI 12345678',
    ]);

    expect($redacted['note'])->toContain('20*******89')
        ->and($redacted['note'])->toContain('12****8')
        ->and($redacted['note'])->not->toContain('20123456789');
});

it('redacta mensajes de error SUNAT con posible PII', function () {
    $redacted = SunatLogRedactor::redactString('Cliente Juan Perez con RUC 20123456789');

    expect($redacted)->not->toContain('20123456789')
        ->and($redacted)->not->toContain('Juan Perez');
});
