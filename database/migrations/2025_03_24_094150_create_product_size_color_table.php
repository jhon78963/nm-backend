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
        Schema::create('product_size_color', function (Blueprint $table) {
            $table->foreignId('product_size_id')->constrained('product_size')->onDelete('cascade');
            $table->foreignId('color_id')->constrained('colors')->onDelete('cascade');
            $table->primary(['product_size_id', 'color_id']);
            $table->integer('stock');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_size_color');
    }
};
