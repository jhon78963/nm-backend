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
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->datetime('creation_time')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->foreignId('creator_user_id')->nullable()->constrained('users');
            $table->datetime('last_modification_time')->nullable();
            $table->foreignId('last_modifier_user_id')->nullable()->constrained('users');
            $table->datetime('deletion_time')->nullable();
            $table->foreignId('deleter_user_id')->nullable()->constrained('users');
            $table->boolean('is_deleted')->default(false);
            $table->string('document_type');
            $table->string('document_number')->unique();
            $table->string('name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
