<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('woocommerce_sync_maps', function (Blueprint $table): void {
            $table->string('payload_checksum', 64)->nullable()->after('variant_key');
            $table->string('image_paths_checksum', 64)->nullable()->after('payload_checksum');
        });
    }

    public function down(): void
    {
        Schema::table('woocommerce_sync_maps', function (Blueprint $table): void {
            $table->dropColumn(['payload_checksum', 'image_paths_checksum']);
        });
    }
};
