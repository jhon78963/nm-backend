<?php

/**
 * SEC-007 — Suite Pest: login inválido, 401, 403, warehouse IDOR, CSRF 419, refresh-token scope.
 *
 * Casos cubiertos:
 *  1. Login inválido → 401 UNAUTHENTICATED
 *  2. Throttle tras 5 intentos → 429 TOO_MANY_REQUESTS
 *  3. Ruta protegida sin token → 401
 *  4. Usuario autenticado sin permiso → 403
 *  5. Vendedora no accede report.index → 403
 *  6. Warehouse IDOR: tenant A + warehouse_id de tenant B → 403
 *  7. CSRF: TokenMismatchException → 419 CSRF_TOKEN_MISMATCH
 *  8. Refresh token no usable como Bearer en rutas API → 403
 */

use App\Administration\Tenant\Models\Tenant;
use App\Administration\User\Models\User;
use App\Auth\Enums\TokenAbility;
use App\Inventory\Warehouse\Models\Warehouse;
use App\Shared\Foundation\Exceptions\ApiExceptionRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

// ─────────────────────────────────────────────────────────────────────────────
// Setup
// ─────────────────────────────────────────────────────────────────────────────

beforeEach(function () {
    app()[PermissionRegistrar::class]->forgetCachedPermissions();

    $this->tenantA = Tenant::create(['name' => 'Tenant A SEC-007', 'is_active' => true]);
    $this->tenantB = Tenant::create(['name' => 'Tenant B SEC-007', 'is_active' => true]);

    $this->warehouseA = sec007Warehouse('Warehouse A SEC-007', $this->tenantA->id);
    $this->warehouseB = sec007Warehouse('Warehouse B SEC-007', $this->tenantB->id);

    foreach ([
        'warehouse.getAll', 'warehouse.get',
        'report.index', 'report.products',
        'inventoryKardex.index',
    ] as $perm) {
        Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
    }

    foreach (['Super Admin', 'Vendedora', 'Vendedor'] as $role) {
        Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
    }

    app()[PermissionRegistrar::class]->forgetCachedPermissions();
});

// ─────────────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────────────

function sec007Warehouse(string $name, int $tenantId): Warehouse
{
    $w = new Warehouse;
    $w->forceFill([
        'name'                 => $name,
        'tenant_id'            => $tenantId,
        'catalog_public_token' => bin2hex(random_bytes(8)),
        'is_deleted'           => false,
    ])->save();

    return $w->fresh();
}

function sec007User(string $email, int $tenantId, ?int $warehouseId = null): User
{
    $u = new User;
    $u->forceFill([
        'username'     => str_replace(['@', '.'], '_', $email),
        'email'        => $email,
        'password'     => Hash::make('SecurePass1!'),
        'tenant_id'    => $tenantId,
        'warehouse_id' => $warehouseId,
    ])->save();

    return $u;
}

function sec007Token(User $user): string
{
    return $user->createToken('test', [TokenAbility::ACCESS_API->value])->plainTextToken;
}

// ─────────────────────────────────────────────────────────────────────────────
// 1. Login inválido → 401
// ─────────────────────────────────────────────────────────────────────────────

it('invalid credentials return 401 UNAUTHENTICATED', function () {
    $this->postJson('/api/auth/login', [
        'username' => 'ghost_user_sec007',
        'password' => 'totally_wrong_password',
    ])->assertUnauthorized()
      ->assertJsonPath('error', 'UNAUTHENTICATED');
});

// ─────────────────────────────────────────────────────────────────────────────
// 2. Throttle tras 5 intentos → 429
// ─────────────────────────────────────────────────────────────────────────────

it('throttle blocks login after 5 failed attempts → 429 TOO_MANY_REQUESTS', function () {
    Cache::flush();

    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/auth/login', [
            'username' => 'brute_sec007',
            'password' => "wrong_pass_{$i}",
        ])->assertStatus(401);
    }

    $this->postJson('/api/auth/login', [
        'username' => 'brute_sec007',
        'password' => 'still_wrong',
    ])->assertStatus(429)
      ->assertJsonPath('error', 'TOO_MANY_REQUESTS');
});

// ─────────────────────────────────────────────────────────────────────────────
// 3. Ruta protegida sin token → 401
// ─────────────────────────────────────────────────────────────────────────────

it('protected route without bearer token returns 401', function () {
    $this->getJson('/api/warehouses')
        ->assertUnauthorized();
});

// ─────────────────────────────────────────────────────────────────────────────
// 4. Usuario autenticado sin permiso → 403
// ─────────────────────────────────────────────────────────────────────────────

it('authenticated user without the required permission returns 403', function () {
    $user = sec007User('noperm@sec007.test', $this->tenantA->id);

    $this->withToken(sec007Token($user))
        ->getJson('/api/warehouses')
        ->assertForbidden();
});

// ─────────────────────────────────────────────────────────────────────────────
// 5. Vendedora no accede report.index → 403
// ─────────────────────────────────────────────────────────────────────────────

it('Vendedora role cannot access the report dashboard → 403', function () {
    $user = sec007User('vendedora@sec007.test', $this->tenantA->id, $this->warehouseA->id);
    $user->syncRoles(['Vendedora']);
    app()[PermissionRegistrar::class]->forgetCachedPermissions();

    $this->withToken(sec007Token($user))
        ->getJson('/api/reports/dashboard')
        ->assertForbidden();
});

// ─────────────────────────────────────────────────────────────────────────────
// 6. Warehouse IDOR: tenant A user + warehouse_id de tenant B → 403
// ─────────────────────────────────────────────────────────────────────────────

it('cross-tenant warehouse_id in inventory request is rejected → 403', function () {
    $user = sec007User('idor@sec007.test', $this->tenantA->id, $this->warehouseA->id);
    $user->givePermissionTo('inventoryKardex.index');
    app()[PermissionRegistrar::class]->forgetCachedPermissions();

    // IDOR attempt: warehouse_id belongs to tenant B, not the authenticated user's tenant A.
    // InventoryKardexReportRequest::authorize() calls userCanAccessWarehouse()
    // which detects the tenant mismatch and returns false → 403.
    $this->withToken(sec007Token($user))
        ->getJson('/api/inventory/kardex?warehouse_id=' . $this->warehouseB->id)
        ->assertForbidden();
});

// ─────────────────────────────────────────────────────────────────────────────
// 7. CSRF: POST stateful sin token → 419
// ─────────────────────────────────────────────────────────────────────────────

it('TokenMismatchException is rendered as 419 CSRF_TOKEN_MISMATCH JSON', function () {
    // Laravel's ValidateCsrfToken bypasses validation in test environments
    // (runningUnitTests() check). We therefore test the exception-rendering layer
    // directly, which is the observable behaviour for API consumers.
    $exception = new TokenMismatchException('CSRF token mismatch.');
    $request   = \Illuminate\Http\Request::create('/api/auth/login', 'POST');

    $response = ApiExceptionRenderer::render($exception, $request);

    expect($response)->not->toBeNull()
        ->and($response->getStatusCode())->toBe(419)
        ->and($response->getData(true)['error'])->toBe('CSRF_TOKEN_MISMATCH')
        ->and($response->getData(true)['success'])->toBeFalse();
});

// ─────────────────────────────────────────────────────────────────────────────
// 8. Refresh token no usable como Bearer en rutas API → 403
// ─────────────────────────────────────────────────────────────────────────────

it('refresh token used as bearer token is rejected by ability middleware → 403', function () {
    $user = sec007User('refresh@sec007.test', $this->tenantA->id);

    // Refresh tokens carry only the 'issue-access-token' ability.
    // Protected routes require 'access-api'. Sanctum 4.x CheckForAnyAbility
    // throws MissingAbilityException (HttpException 403) when the ability is absent.
    $refreshToken = $user->createToken(
        'refresh_token',
        [TokenAbility::ISSUE_ACCESS_TOKEN->value],
    )->plainTextToken;

    $this->withToken($refreshToken)
        ->postJson('/api/auth/me')
        ->assertForbidden();
});
