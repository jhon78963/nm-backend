<?php

/**
 * Administración de usuarios: Super Admin ve cualquier tenant/tienda.
 * Un admin de tenant ve todas las tiendas de su cliente, no las de otros.
 */

use App\Administration\Tenant\Models\Tenant;
use App\Administration\User\Models\User;
use App\Auth\Enums\TokenAbility;
use App\Inventory\Warehouse\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[PermissionRegistrar::class]->forgetCachedPermissions();

    $this->tenantA = Tenant::create(['name' => 'Cliente A', 'is_active' => true]);
    $this->tenantB = Tenant::create(['name' => 'Cliente B', 'is_active' => true]);

    $this->warehouseA1 = userAdminWarehouse('Tienda A1', $this->tenantA->id);
    $this->warehouseA2 = userAdminWarehouse('Tienda A2', $this->tenantA->id);
    $this->warehouseB = userAdminWarehouse('Tienda B', $this->tenantB->id);

    foreach (['user.create', 'user.update', 'user.delete', 'user.getAll', 'user.get'] as $name) {
        Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }

    Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);

    $this->superAdmin = userAdminUser('super.admin@test.com', $this->tenantA->id, $this->warehouseA1->id);
    $this->superAdmin->syncRoles(['Super Admin']);

    $this->tenantAdmin = userAdminUser('tenant.admin@test.com', $this->tenantA->id, $this->warehouseA1->id);
    $this->tenantAdmin->givePermissionTo([
        'user.create', 'user.update', 'user.delete', 'user.getAll', 'user.get',
    ]);

    $this->userA2 = userAdminUser('user.a2@test.com', $this->tenantA->id, $this->warehouseA2->id);
    $this->userB = userAdminUser('user.b@test.com', $this->tenantB->id, $this->warehouseB->id);

    app()[PermissionRegistrar::class]->forgetCachedPermissions();
});

function userAdminWarehouse(string $name, int $tenantId): Warehouse
{
    $warehouse = new Warehouse;
    $warehouse->forceFill([
        'name' => $name,
        'tenant_id' => $tenantId,
        'catalog_public_token' => bin2hex(random_bytes(8)),
        'is_deleted' => false,
    ])->save();

    return $warehouse->fresh();
}

function userAdminUser(string $email, int $tenantId, int $warehouseId): User
{
    $user = new User;
    $user->forceFill([
        'username' => str_replace(['@', '.'], '_', $email),
        'email' => $email,
        'name' => 'Nombre',
        'surname' => 'Apellido',
        'password' => Hash::make('SecurePass1!x'),
        'tenant_id' => $tenantId,
        'warehouse_id' => $warehouseId,
        'is_deleted' => false,
    ])->save();

    return $user;
}

function userAdminToken(User $user): string
{
    return $user->createToken('test', [TokenAbility::ACCESS_API->value])->plainTextToken;
}

function userAdminIds(array $payload): array
{
    return collect($payload['data'] ?? [])
        ->pluck('id')
        ->map(fn ($id) => (int) $id)
        ->all();
}

it('super admin lists users of every warehouse and tenant even with X-Warehouse-Id of one store', function () {
    $response = $this->withToken(userAdminToken($this->superAdmin))
        ->withHeader('X-Warehouse-Id', (string) $this->warehouseA1->id)
        ->getJson('/api/users?limit=50&page=1');

    $response->assertOk();
    $ids = userAdminIds($response->json());

    expect($ids)
        ->toContain($this->superAdmin->id)
        ->toContain($this->tenantAdmin->id)
        ->toContain($this->userA2->id)
        ->toContain($this->userB->id);
});

it('super admin can filter the user list by tenant and warehouse', function () {
    $byTenant = $this->withToken(userAdminToken($this->superAdmin))
        ->getJson('/api/users?limit=50&page=1&tenant_id='.$this->tenantB->id);

    $byTenant->assertOk();
    expect(userAdminIds($byTenant->json()))
        ->toContain($this->userB->id)
        ->not->toContain($this->userA2->id);

    $byWarehouse = $this->withToken(userAdminToken($this->superAdmin))
        ->getJson('/api/users?limit=50&page=1&warehouse_id='.$this->warehouseA2->id);

    $byWarehouse->assertOk();
    expect(userAdminIds($byWarehouse->json()))
        ->toContain($this->userA2->id)
        ->not->toContain($this->userB->id)
        ->not->toContain($this->superAdmin->id);
});

it('super admin can get and update a user from another tenant and warehouse', function () {
    $get = $this->withToken(userAdminToken($this->superAdmin))
        ->withHeader('X-Warehouse-Id', (string) $this->warehouseA1->id)
        ->getJson('/api/users/'.$this->userB->id);

    $get->assertOk()
        ->assertJsonPath('tenantId', $this->tenantB->id)
        ->assertJsonPath('warehouseId', $this->warehouseB->id)
        ->assertJsonPath('tenantName', 'Cliente B')
        ->assertJsonPath('warehouseName', 'Tienda B');

    $update = $this->withToken(userAdminToken($this->superAdmin))
        ->patchJson('/api/users/'.$this->userB->id, [
            'name' => 'Actualizado',
            'tenantId' => $this->tenantA->id,
            'warehouseId' => $this->warehouseA2->id,
        ]);

    $update->assertOk();
    $this->userB->refresh();
    expect((int) $this->userB->tenant_id)->toBe((int) $this->tenantA->id)
        ->and((int) $this->userB->warehouse_id)->toBe((int) $this->warehouseA2->id)
        ->and($this->userB->name)->toBe('Actualizado');
});

it('tenant admin lists users of every warehouse in their tenant but not other tenants', function () {
    $response = $this->withToken(userAdminToken($this->tenantAdmin))
        ->withHeader('X-Warehouse-Id', (string) $this->warehouseA1->id)
        ->getJson('/api/users?limit=50&page=1');

    $response->assertOk();
    $ids = userAdminIds($response->json());

    expect($ids)
        ->toContain($this->superAdmin->id)
        ->toContain($this->tenantAdmin->id)
        ->toContain($this->userA2->id)
        ->not->toContain($this->userB->id);
});

it('tenant admin cannot get or update a user from another tenant', function () {
    $this->withToken(userAdminToken($this->tenantAdmin))
        ->getJson('/api/users/'.$this->userB->id)
        ->assertForbidden();

    $this->withToken(userAdminToken($this->tenantAdmin))
        ->patchJson('/api/users/'.$this->userB->id, [
            'name' => 'No debe',
        ])
        ->assertForbidden();
});

it('tenant admin cannot reassign a user to another tenant', function () {
    $this->withToken(userAdminToken($this->tenantAdmin))
        ->patchJson('/api/users/'.$this->userA2->id, [
            'tenantId' => $this->tenantB->id,
            'warehouseId' => $this->warehouseB->id,
        ])
        ->assertForbidden();
});
