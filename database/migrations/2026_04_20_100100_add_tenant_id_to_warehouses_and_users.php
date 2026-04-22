<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tenants')) {
            return;
        }

        if (DB::table('tenants')->count() === 0) {
            DB::table('tenants')->insert([
                'name' => 'Cliente principal',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $tenantId = (int) DB::table('tenants')->orderBy('id')->value('id');

        if (Schema::hasTable('warehouses') && ! Schema::hasColumn('warehouses', 'tenant_id')) {
            Schema::table('warehouses', function (Blueprint $table) {
                $table->foreignId('tenant_id')->nullable()->constrained('tenants');
            });
            DB::table('warehouses')->whereNull('tenant_id')->update(['tenant_id' => $tenantId]);
        }

        if (Schema::hasTable('users') && ! Schema::hasColumn('users', 'tenant_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->foreignId('tenant_id')->nullable()->constrained('tenants');
            });
            DB::table('users')->whereNull('tenant_id')->update(['tenant_id' => $tenantId]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'tenant_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropForeign(['tenant_id']);
                $table->dropColumn('tenant_id');
            });
        }
        if (Schema::hasColumn('warehouses', 'tenant_id')) {
            Schema::table('warehouses', function (Blueprint $table) {
                $table->dropForeign(['tenant_id']);
                $table->dropColumn('tenant_id');
            });
        }
    }
};
