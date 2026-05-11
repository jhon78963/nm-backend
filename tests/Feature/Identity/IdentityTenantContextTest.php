<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Modules\Administration\Domain\Models\Store;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Shared\Domain\TenantContext;
use App\Modules\Tenant\Domain\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function (): void {
    TenantContext::forget();
});

test('login issues a Sanctum token, sets tenant context for API calls, and scopes stores', function (): void {
    $tenantA = Tenant::query()->create([
        'name' => 'Tenant A',
        'domain' => 'tenant-a.test',
        'is_active' => true,
    ]);

    $tenantB = Tenant::query()->create([
        'name' => 'Tenant B',
        'domain' => 'tenant-b.test',
        'is_active' => true,
    ]);

    $storeA1 = Store::query()->create([
        'tenant_id' => $tenantA->id,
        'name' => 'Store A1',
        'address' => 'Line 1',
        'is_active' => true,
    ]);

    Store::query()->create([
        'tenant_id' => $tenantA->id,
        'name' => 'Store A2',
        'address' => 'Line 2',
        'is_active' => true,
    ]);

    Store::query()->create([
        'tenant_id' => $tenantB->id,
        'name' => 'Store B1',
        'address' => 'Other',
        'is_active' => true,
    ]);

    User::query()->create([
        'name' => 'Operator',
        'email' => 'operator@tenant-a.test',
        'password' => 'secret',
        'tenant_id' => $tenantA->id,
        'store_id' => $storeA1->id,
    ]);

    $login = $this->postJson('/api/login', [
        'email' => 'operator@tenant-a.test',
        'password' => 'secret',
    ]);

    $login->assertOk()->assertJsonStructure(['token']);

    /** @var non-empty-string $token */
    $token = $login->json('token');

    $this->withToken($token)->getJson('/api/tenant/current-context-id')
        ->assertOk()
        ->assertExactJson(['tenant_id' => $tenantA->id]);

    $stores = $this->withToken($token)->getJson('/api/stores')->assertOk()->json();

    expect($stores)->toBeArray()->toHaveCount(2)
        ->and(collect($stores)->pluck('name')->all())
        ->toEqualCanonicalizing(['Store A1', 'Store A2']);
});
