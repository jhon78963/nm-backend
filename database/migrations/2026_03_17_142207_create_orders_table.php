<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->datetime('creation_time')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->foreignId('creator_user_id')->nullable()->constrained('users');
            $table->datetime('last_modification_time')->nullable();
            $table->foreignId('last_modifier_user_id')->nullable()->constrained('users');
            $table->datetime('deletion_time')->nullable();
            $table->foreignId('deleter_user_id')->nullable()->constrained('users');
            $table->boolean('is_deleted')->default(false);

            $table->date('reference_date');
            $table->string('code')->unique()->nullable();
            $table->decimal('total_amount', 10, 2);
            $table->foreignId('origin_warehouse_id')->nullable()->constrained('warehouses');
            $table->foreignId('destination_warehouse_id')->constrained('warehouses');
            $table->string('tracking_number')->nullable();
            $table->enum('type', ['TRIP_PURCHASE', 'ONLINE_PURCHASE', 'WAREHOUSE_TRANSFER', 'WAREHOUSE_IN']);
            $table->enum('status', ['COMPLETED', 'CANCELED', 'PENDING'])->default('COMPLETED');
            $table->text('notes')->nullable();

        });

        // EL DETALLE (La Ropa)
        Schema::create('order_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->onDelete('cascade');
            $table->foreignId('product_id')->constrained('products');
            $table->foreignId('size_id')->constrained('sizes');
            $table->foreignId('color_id')->nullable()->constrained('colors');

            $table->string('product_name_snapshot');
            $table->string('size_name_snapshot');
            $table->string('color_name_snapshot');
            $table->string('sku_snapshot')->nullable();

            $table->integer('quantity');
            $table->string('barcode')->nullable();
            $table->float('purchase_price')->nullable();
            $table->float('sale_price')->nullable();
            $table->float('min_sale_price')->nullable();
            $table->decimal('subtotal', 10, 2);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_details');
        Schema::dropIfExists('orders');
    }
};
