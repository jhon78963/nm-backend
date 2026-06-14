<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->boolean('is_featured')->default(false)->after('cash_discount');
            $table->boolean('is_on_sale')->default(false)->after('is_featured');
            $table->string('woo_status', 20)->nullable()->after('is_on_sale');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn(['is_featured', 'is_on_sale', 'woo_status']);
        });
    }
};
