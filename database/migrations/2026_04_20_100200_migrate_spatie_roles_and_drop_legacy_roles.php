<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Asigna roles Spatie según legacy_roles y elimina role_id / legacy_roles.
     */
    public function up(): void
    {
        if (! Schema::hasTable('legacy_roles') || ! Schema::hasColumn('users', 'role_id')) {
            return;
        }

        if (Schema::hasTable('roles')) {
            foreach (['Super Admin', 'Vendedora'] as $name) {
                if (! DB::table('roles')->where('guard_name', 'web')->where('name', $name)->exists()) {
                    DB::table('roles')->insert([
                        'name' => $name,
                        'guard_name' => 'web',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }

        $map = [
            'Admin' => 'Super Admin',
            'Employee' => 'Vendedora',
        ];

        $users = DB::table('users')->whereNotNull('role_id')->get();
        foreach ($users as $user) {
            $legacy = DB::table('legacy_roles')->where('id', $user->role_id)->first();
            if (! $legacy) {
                continue;
            }
            $spatieName = $map[$legacy->name] ?? 'Vendedora';
            $roleId = DB::table('roles')
                ->where('guard_name', 'web')
                ->where('name', $spatieName)
                ->value('id');
            if (! $roleId) {
                continue;
            }
            DB::table('model_has_roles')->updateOrInsert(
                [
                    'role_id' => $roleId,
                    'model_type' => 'App\\Administration\\User\\Models\\User',
                    'model_id' => $user->id,
                ],
                [
                    'role_id' => $roleId,
                    'model_type' => 'App\\Administration\\User\\Models\\User',
                    'model_id' => $user->id,
                ]
            );
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['role_id']);
            $table->dropColumn('role_id');
        });

        Schema::dropIfExists('legacy_roles');
    }

    public function down(): void
    {
        //
    }
};
