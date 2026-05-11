<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Administration\Domain\Models\Store;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenant\Domain\Models\Tenant;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $tenant = Tenant::query()->create([
            'name' => 'Local Tenant',
            'domain' => 'local.test',
            'is_active' => true,
        ]);

        $store = Store::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Main Store',
            'address' => '—',
            'is_active' => true,
        ]);

        User::query()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'tenant_id' => $tenant->id,
            'store_id' => $store->id,
        ]);
    }
}
