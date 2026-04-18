<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchases', function (Blueprint $table) {
            $table->id();
            $table->datetime('creation_time')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->foreignId('creator_user_id')->nullable()->constrained('users');
            $table->datetime('last_modification_time')->nullable();
            $table->foreignId('last_modifier_user_id')->nullable()->constrained('users');
            $table->datetime('deletion_time')->nullable();
            $table->foreignId('deleter_user_id')->nullable()->constrained('users');
            $table->boolean('is_deleted')->default(false);
            $table->foreignId('vendor_id')->nullable()->constrained('vendors')->nullOnDelete();
            $table->string('supplier_name', 255);
            $table->string('document_note', 500)->nullable();
            $table->date('registered_at');
            $table->foreignId('warehouse_id')->constrained('warehouses');
            $table->string('currency', 8)->default('PEN');
            $table->string('status', 20)->default('ACTIVE');
            $table->decimal('total_subtotal', 14, 2)->default(0);
            $table->longText('payload_json')->nullable();
            $table->datetime('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->foreignId('cancellation_user_id')->nullable()->constrained('users');
        });

        Schema::create('purchase_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_id')->constrained('purchases')->cascadeOnDelete();
            $table->string('line_id', 64)->nullable();
            $table->foreignId('product_id')->constrained('products');
            $table->foreignId('size_id')->constrained('sizes');
            $table->foreignId('product_size_id')->constrained('product_size');
            $table->string('barcode', 64)->nullable();
            $table->decimal('purchase_price', 12, 2)->nullable();
            $table->decimal('sale_price', 12, 2)->nullable();
            $table->decimal('min_sale_price', 12, 2)->nullable();
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->unsignedInteger('size_stock_delta')->default(0);
            $table->boolean('has_color_breakdown')->default(false);
        });

        Schema::create('purchase_line_color_deltas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_line_id')->constrained('purchase_lines')->cascadeOnDelete();
            $table->foreignId('color_id')->constrained('colors');
            $table->unsignedInteger('quantity');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_line_color_deltas');
        Schema::dropIfExists('purchase_lines');
        Schema::dropIfExists('purchases');
    }
};
