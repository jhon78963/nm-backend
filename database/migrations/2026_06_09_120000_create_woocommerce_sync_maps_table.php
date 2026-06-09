<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('woocommerce_sync_maps', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('product_size_id')->nullable();
            $table->unsignedBigInteger('color_id')->nullable();
            $table->unsignedBigInteger('woo_product_id');
            $table->unsignedBigInteger('woo_variation_id')->nullable();
            $table->string('variant_key', 64)->nullable()->unique();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'product_size_id', 'color_id'], 'woo_sync_nm_variant_unique');
            $table->index('woo_product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('woocommerce_sync_maps');
    }
};
