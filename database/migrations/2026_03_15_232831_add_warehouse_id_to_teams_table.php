<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * No-op: warehouse_id was already included in the original create_teams_table migration.
     * This migration is kept for backward-compatibility with production databases that may
     * have run the create_teams_table without the column and then applied this migration.
     */
    public function up(): void
    {
        if (Schema::hasColumn('teams', 'warehouse_id')) {
            return;
        }

        Schema::table('teams', function (Blueprint $table) {
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->default(1);
        });
    }

    public function down(): void
    {
        // Only drop if this migration was the one that created the column.
        // If the column came from create_teams_table, leave it alone.
    }
};
