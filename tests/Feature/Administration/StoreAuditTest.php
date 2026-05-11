<?php

declare(strict_types=1);

namespace Tests\Feature\Administration;

use App\Modules\Administration\Domain\Models\Store;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Shared\Domain\TenantContext;
use App\Modules\Shared\Infrastructure\Persistence\Eloquent\Audit;
use App\Modules\Tenant\Domain\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

afterEach(function (): void {
    TenantContext::forget();
});

test('updating a store writes an audits row with old and new values, tenant context and user', function (): void {
    $tenant = Tenant::query()->create([
        'name' => 'Audit Tenant',
        'domain' => 'audit-tenant.test',
        'is_active' => true,
    ]);

    $store = Store::query()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Old Branch',
        'address' => 'First street',
        'is_active' => true,
    ]);

    $user = User::query()->create([
        'name' => 'Auditor',
        'email' => 'auditor@audit-tenant.test',
        'password' => 'secret',
        'tenant_id' => $tenant->id,
        'store_id' => $store->id,
    ]);

    TenantContext::set((int) $tenant->id);
    Sanctum::actingAs($user);

    $store->refresh()->update(['name' => 'New Branch']);

    $audit = Audit::query()
        ->where('auditable_type', $store->getMorphClass())
        ->where('auditable_id', $store->getKey())
        ->where('event', 'updated')
        ->latest('id')
        ->first();

    expect($audit)->not->toBeNull()
        ->and($audit->tenant_id)->toBe($tenant->id)
        ->and($audit->user_id)->toBe($user->id)
        ->and($audit->old_values['name'] ?? null)->toBe('Old Branch')
        ->and($audit->new_values['name'] ?? null)->toBe('New Branch');
});
