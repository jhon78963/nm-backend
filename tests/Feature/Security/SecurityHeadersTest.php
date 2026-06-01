<?php

/**
 * SEC-004 — Security headers HTTP en rutas api/*.
 */

test('api responses include baseline security headers', function () {
    $response = $this->getJson('/api/auth/csrf-token');

    $response->assertOk();
    $response->assertHeader('X-Frame-Options', 'DENY');
    $response->assertHeader('X-Content-Type-Options', 'nosniff');
    $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    expect($response->headers->get('Permissions-Policy'))->toContain('camera=()');
});

test('hsts header is omitted outside production', function () {
    $response = $this->getJson('/api/auth/csrf-token');

    $response->assertHeaderMissing('Strict-Transport-Security');
});

test('hsts header is set in production', function () {
    app()['env'] = 'production';

    $response = $this->getJson('/api/auth/csrf-token');

    $response->assertHeader(
        'Strict-Transport-Security',
        'max-age=31536000; includeSubDomains'
    );
});
