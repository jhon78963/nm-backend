<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('roles') || Schema::hasTable('legacy_roles')) {
            return;
        }

        if (Schema::hasColumn('roles', 'guard_name')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['role_id']);
        });

        Schema::rename('roles', 'legacy_roles');

        Schema::table('users', function (Blueprint $table) {
            $table->foreign('role_id')->references('id')->on('legacy_roles');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('legacy_roles') || Schema::hasTable('roles')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['role_id']);
        });

        Schema::rename('legacy_roles', 'roles');

        Schema::table('users', function (Blueprint $table) {
            $table->foreign('role_id')->references('id')->on('roles');
        });
    }
};
