<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('tenant_id')->after('id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('store_id')->after('tenant_id')->constrained('stores')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropForeign(['tenant_id']);
            $table->dropForeign(['store_id']);
            $table->dropColumn(['tenant_id', 'store_id']);
        });
    }
};
