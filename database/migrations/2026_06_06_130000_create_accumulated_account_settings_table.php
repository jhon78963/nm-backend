<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accumulated_account_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->decimal('cash_balance', 10, 2)->default(0);
            $table->decimal('digital_balance', 10, 2)->default(0);
            $table->string('tracking_start_month', 7)->nullable()->comment('YYYY-MM');
            $table->dateTime('creation_time')->useCurrent();
            $table->foreignId('creator_user_id')->nullable()->constrained('users');
            $table->dateTime('last_modification_time')->nullable();
            $table->foreignId('last_modifier_user_id')->nullable()->constrained('users');
            $table->unique('warehouse_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accumulated_account_settings');
    }
};
