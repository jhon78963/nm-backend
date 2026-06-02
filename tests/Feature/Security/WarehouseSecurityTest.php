<?php

/**
 * SEC-010 — Warehouse desde sesión: tests de seguridad del resolver de almacén.
 *
 * Verifica que WarehouseIdForInventoryResolver bloquea IDOR vía cabecera X-Warehouse-Id
 * y que WarehouseQueryFilter aplica el aislamiento por almacén correctamente.
 *
 * Casos cubiertos:
 *  1. Usuario regular — cabecera X-Warehouse-Id de otro tenant → AuthorizationException
 *  2. Usuario regular — cabecera X-Warehouse-Id mismo tenant diferente almacén → excepción
 *  3. Usuario regular — cabecera X-Warehouse-Id con su propio almacén → permitido
 *  4. Sin cabecera — resolver usa warehouse_id del usuario → no lanza excepción
 *  5. Super Admin — cabecera X-Warehouse-Id de su mismo tenant → permitido
 *  6. Super Admin — cabecera X-Warehouse-Id de otro tenant → excepción
 *  7. Usuario sin almacén asignado — cualquier X-Warehouse-Id → excepción
 *  8. HTTP feature: cross-tenant via query param en ruta kardex → 403
 */

use App\Administration\Tenant\Models\Tenant;
use App\Administration\User\Models\User;
use App\Inventory\InventoryLedger\Support\WarehouseIdForInventoryResolver;
use App\Inventory\Warehouse\Models\Warehouse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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

    $this->tenantA = Tenant::create(['name' => 'Tenant A SEC-010', 'is_active' => true]);
    $this->tenantB = Tenant::create(['name' => 'Tenant B SEC-010', 'is_active' => true]);

    $this->warehouseA1 = sec010Warehouse('Warehouse A1', $this->tenantA->id);
    $this->warehouseA2 = sec010Warehouse('Warehouse A2', $this->tenantA->id);
    $this->warehouseB  = sec010Warehouse('Warehouse B',  $this->tenantB->id);

    Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
});

// ─────────────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────────────

function sec010Warehouse(string $name, int $tenantId): Warehouse
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

function sec010User(int $tenantId, ?int $warehouseId = null): User
{
    static $seq = 0;
    $seq++;
    $u = new User;
    $u->forceFill([
        'username'     => "u_sec010_{$seq}",
        'email'        => "u_sec010_{$seq}@test.com",
        'password'     => Hash::make('SecurePass1!'),
        'tenant_id'    => $tenantId,
        'warehouse_id' => $warehouseId,
    ])->save();

    return $u;
}

function sec010RequestWithHeader(int $warehouseId): Request
{
    return Request::create('/api/test', 'GET', [], [], [], [
        'HTTP_X_WAREHOUSE_ID' => (string) $warehouseId,
    ]);
}

function sec010Token(User $user): string
{
    return $user->createToken('test', [\App\Auth\Enums\TokenAbility::ACCESS_API->value])->plainTextToken;
}

// ─────────────────────────────────────────────────────────────────────────────
// 1. Cross-tenant X-Warehouse-Id → rechazado
// ─────────────────────────────────────────────────────────────────────────────

it('rejects X-Warehouse-Id header pointing to a cross-tenant warehouse', function () {
    $user = sec010User($this->tenantA->id, $this->warehouseA1->id);
    Auth::setUser($user);

    $request = sec010RequestWithHeader($this->warehouseB->id);

    expect(fn () => WarehouseIdForInventoryResolver::resolve($request))
        ->toThrow(AuthorizationException::class);
});

// ─────────────────────────────────────────────────────────────────────────────
// 2. Mismo tenant, diferente almacén (regular user) → rechazado
// ─────────────────────────────────────────────────────────────────────────────

it('rejects X-Warehouse-Id for same-tenant different warehouse (regular user)', function () {
    $user = sec010User($this->tenantA->id, $this->warehouseA1->id);
    Auth::setUser($user);

    $request = sec010RequestWithHeader($this->warehouseA2->id);

    expect(fn () => WarehouseIdForInventoryResolver::resolve($request))
        ->toThrow(AuthorizationException::class);
});

// ─────────────────────────────────────────────────────────────────────────────
// 3. Usuario envía su propio almacén → permitido (redundante pero válido)
// ─────────────────────────────────────────────────────────────────────────────

it('allows X-Warehouse-Id that matches the user own warehouse', function () {
    $user = sec010User($this->tenantA->id, $this->warehouseA1->id);
    Auth::setUser($user);

    $request = sec010RequestWithHeader($this->warehouseA1->id);

    expect(WarehouseIdForInventoryResolver::resolve($request))
        ->toBe($this->warehouseA1->id);
});

// ─────────────────────────────────────────────────────────────────────────────
// 4. Sin cabecera → fallback a warehouse_id del usuario
// ─────────────────────────────────────────────────────────────────────────────

it('falls back to user warehouse_id when no X-Warehouse-Id header is sent', function () {
    $user = sec010User($this->tenantA->id, $this->warehouseA1->id);
    Auth::setUser($user);

    $request = Request::create('/api/test', 'GET');

    expect(WarehouseIdForInventoryResolver::resolve($request))
        ->toBe($this->warehouseA1->id);
});

// ─────────────────────────────────────────────────────────────────────────────
// 5. Super Admin — mismo tenant → permitido
// ─────────────────────────────────────────────────────────────────────────────

it('allows Super Admin to select any same-tenant warehouse via X-Warehouse-Id', function () {
    $admin = sec010User($this->tenantA->id, null);
    $admin->assignRole('Super Admin');
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
    Auth::setUser($admin->fresh());

    $request = sec010RequestWithHeader($this->warehouseA2->id);

    expect(WarehouseIdForInventoryResolver::resolve($request))
        ->toBe($this->warehouseA2->id);
});

// ─────────────────────────────────────────────────────────────────────────────
// 6. Super Admin — otro tenant → rechazado
// ─────────────────────────────────────────────────────────────────────────────

it('rejects Super Admin X-Warehouse-Id pointing to a cross-tenant warehouse', function () {
    $admin = sec010User($this->tenantA->id, null);
    $admin->assignRole('Super Admin');
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
    Auth::setUser($admin->fresh());

    $request = sec010RequestWithHeader($this->warehouseB->id);

    expect(fn () => WarehouseIdForInventoryResolver::resolve($request))
        ->toThrow(AuthorizationException::class);
});

// ─────────────────────────────────────────────────────────────────────────────
// 7. Usuario sin almacén — cualquier X-Warehouse-Id → rechazado
// ─────────────────────────────────────────────────────────────────────────────

it('rejects any X-Warehouse-Id for a user with no assigned warehouse', function () {
    $user = sec010User($this->tenantA->id, null);
    Auth::setUser($user);

    $request = sec010RequestWithHeader($this->warehouseA1->id);

    expect(fn () => WarehouseIdForInventoryResolver::resolve($request))
        ->toThrow(AuthorizationException::class);
});

// ─────────────────────────────────────────────────────────────────────────────
// 8. HTTP: cross-tenant warehouse_id via query param en kardex → 403
// ─────────────────────────────────────────────────────
