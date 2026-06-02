<?php

/**
 * SEC-011 + SEC-013 — Política de contraseñas y reset sin enumeración de emails.
 */

use App\Auth\Services\AuthService;
use App\Auth\Support\PasswordPolicy;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;

beforeEach(function () {
    Http::fake([
        'api.pwnedpasswords.com/*' => Http::response('', 200),
    ]);
});

function sec011StrongPassword(): string
{
    return 'SecurePass1!@#';
}

// ─────────────────────────────────────────────────────────────────────────────
// SEC-011 — Password policy
// ─────────────────────────────────────────────────────────────────────────────

it('rejects weak passwords on reset-password with validation errors', function () {
    $response = $this->postJson('/api/auth/reset-password', [
        'token' => 'fake-token',
        'email' => 'ghost@sec011.test',
        'password' => 'short1',
        'password_confirmation' => 'short1',
    ]);

    $response->assertUnprocessable()
        ->assertJsonPath('success', false)
        ->assertJsonPath('error', 'VALIDATION_ERROR');

    expect(strtolower((string) $response->json('message')))->toContain('contraseña');
});

it('enforces min 12 chars with mixed case numbers and symbols via PasswordPolicy', function () {
    $validator = Validator::make(
        [
            'password' => 'alllowercase12!',
            'password_confirmation' => 'alllowercase12!',
        ],
        ['password' => PasswordPolicy::rules()],
        PasswordPolicy::messages(),
    );

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('password'))->toBeTrue();
});

it('accepts strong passwords on reset-password when broker succeeds', function () {
    $this->mock(AuthService::class, function ($mock): void {
        $mock->shouldReceive('resetPasswordWithToken')
            ->once()
            ->andReturn(Password::PASSWORD_RESET);
    });

    $this->postJson('/api/auth/reset-password', [
        'token' => 'valid-token',
        'email' => 'user@sec011.test',
        'password' => sec011StrongPassword(),
        'password_confirmation' => sec011StrongPassword(),
    ])->assertOk()
      ->assertJsonPath('message', 'Contraseña establecida correctamente. Ya puedes iniciar sesión.');
});

// ─────────────────────────────────────────────────────────────────────────────
// SEC-013 — Forgot / reset password without email enumeration
// ─────────────────────────────────────────────────────────────────────────────

it('forgot-password returns identical response for any email address', function () {
    $this->mock(AuthService::class, function ($mock): void {
        $mock->shouldReceive('sendPasswordResetLink')->twice();
    });

    $registeredResponse = $this->postJson('/api/auth/forgot-password', [
        'email' => 'registered@sec011.test',
    ]);

    $unknownResponse = $this->postJson('/api/auth/forgot-password', [
        'email' => 'unknown@sec011.test',
    ]);

    $registeredResponse->assertOk()
        ->assertJsonPath('message', AuthService::PASSWORD_RESET_REQUEST_MESSAGE);

    $unknownResponse->assertOk()
        ->assertJsonPath('message', AuthService::PASSWORD_RESET_REQUEST_MESSAGE);

    expect($registeredResponse->json())->toBe($unknownResponse->json());
});

it('reset-password failure uses a generic message for invalid user and invalid token', function () {
    $payload = [
        'token' => 'some-token',
        'email' => 'user@sec011.test',
        'password' => sec011StrongPassword(),
        'password_confirmation' => sec011StrongPassword(),
    ];

    $this->mock(AuthService::class, function ($mock): void {
        $mock->shouldReceive('resetPasswordWithToken')
            ->twice()
            ->andReturn(Password::INVALID_USER, Password::INVALID_TOKEN);
    });

    $invalidUserResponse = $this->postJson('/api/auth/reset-password', $payload);
    $invalidTokenResponse = $this->postJson('/api/auth/reset-password', $payload);

    $invalidUserResponse->assertUnprocessable()
        ->assertJsonPath('message', AuthService::PASSWORD_RESET_FAILURE_MESSAGE);

    $invalidTokenResponse->assertUnprocessable()
        ->assertJsonPath('message', AuthService::PASSWORD_RESET_FAILURE_MESSAGE);

    expect($invalidUserResponse->json())->toBe($invalidTokenResponse->json());
});
