<?php

/**
 * SEC-002 — Los roles built-in Super Admin, Vendedora y Vendedor no pueden
 * modificarse ni eliminarse vía API (incluso por Super Admin).
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

const SEC002_FORBIDDEN_MESSAGE = 'Los roles del sistema (Super Admin, Vendedora y Vendedor) no pueden modificarse ni eliminarse.';

beforeEach(function () {
    app()[PermissionRegistrar::class]->forgetCachedPermissions();

    $this->tenant = Tenant::create(['name' => 'Tenant SEC-002', 'is_active' => true]);

    foreach (['role.syncPermissions', 'role.update', 'role.delete', 'pos.checkout'] as $name) {
        Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }

    $this->superAdminRole = Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
    $this->vendedoraRole = Role::firstOrCreate(['name' => 'Vendedora', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'Vendedor', 'guard_name' => 'web']);

    $this->customRole = Role::create(['name' => 'Rol Custom SEC-002', 'guard_name' => 'web']);
    $this->customRole->tenant_id = $this->tenant->id;
    $this->customRole->save();

    $this->superAdmin = sec002CreateUser('super@sec002.test', $this->tenant->id);
    $this->superAdmin->syncRoles(['Super Admin']);

    $this->tenantAdmin = sec002CreateUser('admin@sec002.test', $this->tenant->id);
    $this->tenantAdmin->givePermissionTo(['role.syncPermissions', 'role.update', 'role.delete']);

    app()[PermissionRegistrar::class]->forgetCachedPermissions();
});

function sec002CreateUser(string $email, int $tenantId): User
{
    $user = new User;
    $user->forceFill([
        'username' => str_replace(['@', '.'], '_', $email),
        'email' => $email,
        'password' => Hash::make('Test1234!'),
        'tenant_id' => $tenantId,
        'warehouse_id' => null,
    ])->save();

    return $user;
}

function sec002TokenFor(User $user): string
{
    return $user->createToken('test', [TokenAbility::ACCESS_API->value])->plainTextToken;
}

it('sync permissions on Super Admin role → 403 with Spanish message', function () {
    $this->withToken(sec002TokenFor($this->superAdmin))
        ->postJson("/api/roles/{$this->superAdminRole->id}/sync-permissions", [
            'permissions' => ['pos.checkout'],
        ])
        ->assertForbidden()
        ->assertJsonPath('message', SEC002_FORBIDDEN_MESSAGE);
});

it('sync permissions on custom tenant role → 200', function () {
    $this->withToken(sec002TokenFor($this->tenantAdmin))
        ->postJson("/api/roles/{$this->customRole->id}/sync-permissions", [
            'permissions' => ['pos.checkout'],
        ])
        ->assertOk()
        ->assertJsonPath('message', 'Permisos sincronizados.');
});

it('delete built-in Vendedora role → 403', function () {
    $this->withToken(sec002TokenFor($this->superAdmin))
        ->deleteJson("/api/roles/{$this->vendedoraRole->id}")
        ->assertForbidden()
        ->assertJsonPath('message', SEC002_FORBIDDEN_MESSAGE);

    expect(Role::find($this->vendedoraRole->id))->not->toBeNull();
});

it('update built-in Vendedor role → 403', function () {
    $vendedor = Role::where('name', 'Vendedor')->where('guard_name', 'web')->first();

    $this->withToken(sec002TokenFor($this->tenantAdmin))
        ->patchJson("/api/roles/{$vendedor->id}", ['name' => 'Vendedor Hacked'])
        ->assertForbidden()
        ->assertJsonPath('message', SEC002_FORBIDDEN_MESSAGE);
});
