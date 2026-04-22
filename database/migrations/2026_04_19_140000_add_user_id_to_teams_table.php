<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            if (! Schema::hasColumn('teams', 'user_id')) {
                $table->foreignId('user_id')->nullable()->constrained('users');
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('teams', 'user_id')) {
            Schema::table('teams', function (Blueprint $table) {
                $table->dropForeign(['user_id']);
                $table->dropColumn('user_id');
            });
        }
    }
};
