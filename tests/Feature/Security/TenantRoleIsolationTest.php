<?php

/**
 * SEC-001 — Feature test: RBAC tenant-scope isolation.
 *
 * Verifica que un admin del tenant A no puede modificar roles del tenant B
 * ni roles de sistema (tenant_id NULL), y que Super Admin puede modificar cualquier rol.
 *
 * Estrategia implementada: B (tenant_id en tabla roles, restricción en controlador).
 * Ver: app/Administration/Role/Concerns/GuardsRoleTenantScope.php
 */

use App\Administration\Tenant\Models\Tenant;
use App\Administration\User\Models\User;
use App\Auth\Enums\TokenAbility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Setup
// ---------------------------------------------------------------------------

beforeEach(function () {
    app()[PermissionRegistrar::class]->forgetCachedPermissions();

    $this->tenantA = Tenant::create(['name' => 'Tenant A', 'is_active' => true]);
    $this->tenantB = Tenant::create(['name' => 'Tenant B', 'is_active' => true]);

    // Permissions requeridos por el middleware de rutas
    foreach ([
        'role.syncPermissions', 'role.create', 'role.update',
        'role.delete', 'role.getAll', 'role.get',
        'pos.checkout',
    ] as $name) {
        Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }

    // Rol de sistema (tenant_id NULL → solo Super Admin puede modificarlo)
    $this->systemRole = tap(
        Role::firstOrCreate(['name' => 'Vendedora', 'guard_name' => 'web'])
    )->refresh();

    // Super Admin role (también sistema)
    $superAdminRole = Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);

    // Roles custom por tenant
    $this->roleA = createRole('Custom Role A', $this->tenantA->id);
    $this->roleB = createRole('Custom Role B', $this->tenantB->id);

    // Usuarios
    $this->superAdmin = createUser('superadmin@sec.test', $this->tenantA->id);
    $this->superAdmin->syncRoles(['Super Admin']);

    $this->adminA = createUser('admin_a@sec.test', $this->tenantA->id);
    $this->adminA->givePermissionTo([
        'role.syncPermissions', 'role.create', 'role.update', 'role.delete', 'role.getAll',
    ]);

    $this->adminB = createUser('admin_b@sec.test', $this->tenantB->id);
    $this->adminB->givePermissionTo(['role.syncPermissions', 'role.update', 'role.delete']);

    app()[PermissionRegistrar::class]->forgetCachedPermissions();
});

// ---------------------------------------------------------------------------
// Helper functions
// ---------------------------------------------------------------------------

function createRole(string $name, int $tenantId): Role
{
    $role = Role::create(['name' => $name, 'guard_name' => 'web']);
    $role->tenant_id = $tenantId;
    $role->save();

    return $role->fresh();
}

function createUser(string $email, int $tenantId): User
{
    $user = new User;
    $user->forceFill([
        'username'   => str_replace(['@', '.'], '_', $email),
        'email'      => $email,
        'password'   => Hash::make('Test1234!'),
        'tenant_id'  => $tenantId,
        'warehouse_id' => null,
    ])->save();

    return $user;
}

function tokenFor(User $user): string
{
    return $user->createToken('test', [TokenAbility::ACCESS_API->value])->plainTextToken;
}

// ---------------------------------------------------------------------------
// syncPermissions — aislamiento entre tenants
// ---------------------------------------------------------------------------

it('tenant A admin cannot sync permissions on tenant B role → 403', function () {
    $response = $this->withToken(tokenFor($this->adminA))
        ->postJson("/api/roles/{$this->roleB->id}/sync-permissions", [
            'permissions' => ['pos.checkout'],
        ]);

    $response->assertForbidden();
});

it('tenant B admin cannot sync permissions on tenant A role → 403', function () {
    $response = $this->withToken(tokenFor($this->adminB))
        ->postJson("/api/roles/{$this->roleA->id}/sync-permissions", [
            'permissions' => ['pos.checkout'],
        ]);

    $response->assertForbidden();
});

it('tenant A admin cannot sync permissions on system role → 403', function () {
    $response = $this->withToken(tokenFor($this->adminA))
        ->postJson("/api/roles/{$this->systemRole->id}/sync-permissions", [
            'permissions' => ['pos.checkout'],
        ]);

    $response->assertForbidden();
});

it('tenant A admin can sync permissions on own tenant role → 200', function () {
    $response = $this->withToken(tokenFor($this->adminA))
        ->postJson("/api/roles/{$this->roleA->id}/sync-permissions", [
            'permissions' => ['pos.checkout'],
        ]);

    $response->assertOk()
        ->assertJsonPath('message', 'Permisos sincronizados.');
});

it('super admin can sync permissions on any tenant role → 200', function () {
    $responseA = $this->withToken(tokenFor($this->superAdmin))
        ->postJson("/api/roles/{$this->roleA->id}/sync-permissions", [
            'permissions' => ['pos.checkout'],
        ]);
    $responseA->assertOk();

    $responseB = $this->withToken(tokenFor($this->superAdmin))
        ->postJson("/api/roles/{$this->roleB->id}/sync-permissions", [
            'permissions' => ['pos.checkout'],
        ]);
    $responseB->assertOk();
});

it('super admin cannot sync permissions on built-in system role → 403 (SEC-002)', function () {
    $response = $this->withToken(tokenFor($this->superAdmin))
        ->postJson("/api/roles/{$this->systemRole->id}/sync-permissions", [
            'permissions' => ['pos.checkout'],
        ]);

    $response->assertForbidden()
        ->assertJsonPath(
            'message',
            'Los roles del sistema (Super Admin, Vendedora y Vendedor) no pueden modificarse ni eliminarse.',
        );
});

// ---------------------------------------------------------------------------
// delete — aislamiento entre tenants
// ---------------------------------------------------------------------------

it('tenant A admin cannot delete tenant B role → 403', function () {
    $response = $this->withToken(tokenFor($this->adminA))
        ->deleteJson("/api/roles/{$this->roleB->id}");

    $response->assertForbidden();
});

it('tenant A admin cannot delete system role → 403', function () {
    $response = $this->withToken(tokenFor($this->adminA))
        ->deleteJson("/api/roles/{$this->systemRole->id}");

    $response->assertForbidden();
});

// ---------------------------------------------------------------------------
// update — aislamiento entre tenants
// ---------------------------------------------------------------------------

it('tenant A admin cannot update tenant B role → 403', function () {
    $response = $this->withToken(tokenFor($this->adminA))
        ->patchJson("/api/roles/{$this->roleB->id}", ['name' => 'Hacked']);

    $response->assertForbidden();
});

// ---------------------------------------------------------------------------
// create — el nuevo rol queda scoped al tenant del actor
// ---------------------------------------------------------------------------

it('tenant A admin creates role scoped to tenant A', function () {
    $response = $this->withToken(tokenFor($this->adminA))
        ->postJson('/api/roles', ['name' => 'Nuevo Rol A']);

    $response->assertCreated();

    $created = Role::where('name', 'Nuevo Rol A')->first();
    expect($created)->not->toBeNull()
        ->and((int) $created->tenant_id)->toBe((int) $this->tenantA->id);
});

it('super admin creates system role (tenant_id null)', function () {
    $response = $this->withToken(tokenFor($this->superAdmin))
        ->postJson('/api/roles', ['name' => 'Rol Sistema Nuevo']);

    $response->assertCreated();

    $created = Role::where('name', 'Rol Sistema Nuevo')->first();
    expect($created)->not->toBeNull()
        ->and($created->tenant_id)->toBeNull();
});

// ---------------------------------------------------------------------------
// getAll — visibilidad tenant-scoped
// ---------------------------------------------------------------------------

it('tenant A admin only sees own tenant roles in getAll', function () {
    $this->withToken(tokenFor($this->adminA))
        ->getJson('/api/roles?limit=100')
        ->assertOk()
        ->assertJsonFragment(['name' => 'Custom Role A'])
        ->assertJsonMissing(['name' => 'Custom Role B'])
        ->assertJsonMissing(['name' => 'Vendedora']); // system role hidden
});

it('super admin sees all roles in getAll', function () {
    $response = $this->withToken(tokenFor($this->superAdmin))
        ->getJson('/api/roles?limit=100');

    $response->assertOk();
    $names = collect($response->json('data'))->pluck('name');
    expect($names)->toContain('Custom Role A')
        ->and($names)->toContain('Custom Role B')
        ->and($names)->toContain('Vendedora');
});
