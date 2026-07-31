<?php

namespace Database\Seeders;

use App\Administrations\Tenants\Models\Tenant;
use Illuminate\Database\Seeder;

class TenantSeeder extends Seeder
{
    public function run(): void
    {
        Tenant::query()->firstOrCreate(
            ['name' => 'Cliente principal'],
            ['is_active' => true]
        );
    }
}
