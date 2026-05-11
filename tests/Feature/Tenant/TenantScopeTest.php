<?php

declare(strict_types=1);

namespace Tests\Feature\Tenant;

use App\Modules\Shared\Domain\TenantContext;
use App\Modules\Shared\Infrastructure\Traits\BelongsToTenant;
use App\Modules\Tenant\Domain\Models\Tenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Schema::dropIfExists('fake_tenant_scoped_items');

    Schema::create('fake_tenant_scoped_items', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
        $table->string('name');
        $table->timestamps();
    });

    TenantContext::forget();
});

afterEach(function (): void {
    TenantContext::forget();
});

test('BelongsToTenant scope limits rows to the active tenant context', function (): void {
    $tenantApple = Tenant::query()->create([
        'name' => 'Apple',
        'domain' => 'apple.test',
        'is_active' => true,
    ]);

    $tenantBerry = Tenant::query()->create([
        'name' => 'Berry',
        'domain' => 'berry.test',
        'is_active' => true,
    ]);

    FakeTenantScopedItem::withoutGlobalScopes()->insert([
        [
            'tenant_id' => $tenantApple->id,
            'name' => 'item-a',
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'tenant_id' => $tenantBerry->id,
            'name' => 'item-b',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    TenantContext::set((int) $tenantApple->id);

    $visible = FakeTenantScopedItem::query()->orderBy('id')->get();

    expect($visible)->toHaveCount(1)
        ->and($visible->first()->name)->toBe('item-a');
});

test('BelongsToTenant assigns tenant_id on creating from context', function (): void {
    $tenant = Tenant::query()->create([
        'name' => 'Cherry',
        'domain' => 'cherry.test',
        'is_active' => true,
    ]);

    TenantContext::set((int) $tenant->id);

    $created = FakeTenantScopedItem::query()->create(['name' => 'from-context']);

    expect($created->tenant_id)->toBe($tenant->id);
});

#[Fillable(['name', 'tenant_id'])]
final class FakeTenantScopedItem extends Model
{
    use BelongsToTenant;

    protected $table = 'fake_tenant_scoped_items';
}
