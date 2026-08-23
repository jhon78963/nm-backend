<?php

use App\Administration\User\Models\User;
use App\Administration\User\Support\SuperAdminRole;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('users')) {
            return;
        }

        User::query()
            ->role(SuperAdminRole::NAME)
            ->update([
                'tenant_id' => null,
                'warehouse_id' => null,
            ]);
    }

    public function down(): void
    {
        // No se restauran asignaciones legacy de Super Admin.
    }
};
