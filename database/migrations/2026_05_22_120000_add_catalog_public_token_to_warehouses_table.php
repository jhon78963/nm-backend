<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('warehouses')) {
            return;
        }

        Schema::table('warehouses', function (Blueprint $table) {
            if (! Schema::hasColumn('warehouses', 'catalog_public_token')) {
                $table->string('catalog_public_token', 64)->nullable()->unique()->after('name');
            }
        });

        $rows = DB::table('warehouses')
            ->whereNull('catalog_public_token')
            ->where('is_deleted', false)
            ->get(['id']);

        foreach ($rows as $row) {
            DB::table('warehouses')
                ->where('id', $row->id)
                ->update(['catalog_public_token' => Str::random(40)]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('warehouses')) {
            return;
        }

        Schema::table('warehouses', function (Blueprint $table) {
            if (Schema::hasColumn('warehouses', 'catalog_public_token')) {
                $table->dropUnique(['catalog_public_token']);
                $table->dropColumn('catalog_public_token');
            }
        });
    }
};
