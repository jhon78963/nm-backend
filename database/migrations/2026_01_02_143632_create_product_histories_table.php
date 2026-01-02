<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('product_histories', function (Blueprint $table) {
            $table->id();
            $table->datetime('creation_time')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->foreignId('creator_user_id')->nullable()->constrained('users');
            $table->datetime('last_modification_time')->nullable();
            $table->foreignId('last_modifier_user_id')->nullable()->constrained('users');
            $table->datetime('deletion_time')->nullable();
            $table->foreignId('deleter_user_id')->nullable()->constrained('users');
            $table->boolean('is_deleted')->default(false);

            $table->foreignId('product_id')->constrained('products')->onDelete('cascade');
            $table->string('entity_type'); // Ejemplo: 'PRODUCT', 'SIZE', 'COLOR'
            $table->unsignedBigInteger('entity_id'); // ID de la talla o color específico
            $table->string('event_type'); // 'CREATED', 'UPDATED', 'DELETED'

            // Detalle de cambios (Guardamos JSON para flexibilidad)
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_histories');
    }
};
