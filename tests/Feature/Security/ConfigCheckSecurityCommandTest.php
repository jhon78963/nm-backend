<?php

/**
 * SEC-006 — Validación ligera de configuración de producción.
 */

use Illuminate\Support\Facades\Config;

test('config check security passes with production-safe settings', function () {
    Config::set('app.debug', false);
    Config::set('app.key', 'base64:'.base64_encode(random_bytes(32)));
    Config::set('app.url', 'https://api.example.com');
    Config::set('session.secure', true);
    Config::set('session.encrypt', true);
    Config::set('session.same_site', 'none');
    Config::set('sanctum.stateful', ['app.example.com']);
    Config::set('cors.allowed_origins', ['https://app.example.com']);

    $this->artisan('config:check-security')
        ->assertSuccessful();
});

test('config check security fails when session cookies are insecure', function () {
    Config::set('app.debug', false);
    Config::set('app.key', 'base64:'.base64_encode(random_bytes(32)));
    Config::set('app.url', 'https://api.example.com');
    Config::set('session.secure', false);
    Config::set('session.encrypt', true);
    Config::set('sanctum.stateful', ['app.example.com']);
    Config::set('cors.allowed_origins', ['https://app.example.com']);

    $this->artisan('config:check-security')
        ->assertFailed();
});
