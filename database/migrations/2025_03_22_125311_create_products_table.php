<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->datetime('creation_time')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->integer('creator_user_id')->nullable();
            $table->foreign('creator_user_id')->references('id')->on('users');
            $table->datetime('last_modification_time')->nullable();
            $table->integer('last_modifier_user_id')->nullable();
            $table->foreign('last_modifier_user_id')->references('id')->on('users');
            $table->boolean('is_deleted')->default(false);
            $table->integer('deleter_user_id')->nullable();
            $table->foreign('deleter_user_id')->references('id')->on('users');
            $table->datetime('deletion_time')->nullable();
            $table->string('name');
            $table->string('description')->nullable();
            $table->integer('stock')->nullable()->default(0);
            $table->float('purchase_price');
            $table->float('sale_price');
            $table->float('min_sale_price');
            $table->enum('status', ['AVAILABLE', 'LIMITED_STOCK', 'OUT_OF_STOCK', 'DISCONTINUED'])
                ->default('AVAILABLE');
            $table->unsignedBigInteger('gender_id');
            $table->foreign('gender_id')->references('id')->on('genders');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
