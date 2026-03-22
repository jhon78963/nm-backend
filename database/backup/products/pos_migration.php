<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

Schema::create('genders', function (Blueprint $table) {
    $table->id();
    $table->datetime('creation_time')->default(DB::raw('CURRENT_TIMESTAMP'));
    $table->foreignId('creator_user_id')->nullable()->constrained('users');
    $table->datetime('last_modification_time')->nullable();
    $table->foreignId('last_modifier_user_id')->nullable()->constrained('users');
    $table->datetime('deletion_time')->nullable();
    $table->foreignId('deleter_user_id')->nullable()->constrained('users');
    $table->boolean('is_deleted')->default(false);
    $table->string('name');
    $table->string('short_name');
});

Schema::create('warehouses', function (Blueprint $table) {
    $table->id();
    $table->datetime('creation_time')->default(DB::raw('CURRENT_TIMESTAMP'));
    $table->foreignId('creator_user_id')->nullable()->constrained('users');
    $table->datetime('last_modification_time')->nullable();
    $table->foreignId('last_modifier_user_id')->nullable()->constrained('users');
    $table->datetime('deletion_time')->nullable();
    $table->foreignId('deleter_user_id')->nullable()->constrained('users');
    $table->boolean('is_deleted')->default(false);
    $table->string('name');
});

Schema::create('products', function (Blueprint $table) {
    $table->id();
    $table->datetime('creation_time')->default(DB::raw('CURRENT_TIMESTAMP'));
    $table->foreignId('creator_user_id')->nullable()->constrained('users');
    $table->datetime('last_modification_time')->nullable();
    $table->foreignId('last_modifier_user_id')->nullable()->constrained('users');
    $table->datetime('deletion_time')->nullable();
    $table->foreignId('deleter_user_id')->nullable()->constrained('users');
    $table->boolean('is_deleted')->default(false);
    $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->default(1);
    $table->foreignId('gender_id')->constrained('genders');
    $table->string('name');
    $table->string('description')->nullable();
    $table->string('barcode')->nullable();
    $table->string('percentage_discount')->nullable();
    $table->integer('cash_discount')->nullable();
    $table->enum('status', ['AVAILABLE', 'LIMITED_STOCK', 'OUT_OF_STOCK', 'DISCONTINUED'])->default('AVAILABLE');
});

Schema::create('product_image', function (Blueprint $table) {
    $table->foreignId('product_id')->constrained('products');
    $table->string('path');
    $table->primary(['product_id', 'path']);
    $table->string('size');
    $table->string('name');
    $table->boolean('status')->default(true);
});

Schema::create('size_types', function (Blueprint $table) {
    $table->id();
    $table->datetime('creation_time')->default(DB::raw('CURRENT_TIMESTAMP'));
    $table->foreignId('creator_user_id')->nullable()->constrained('users');
    $table->datetime('last_modification_time')->nullable();
    $table->foreignId('last_modifier_user_id')->nullable()->constrained('users');
    $table->datetime('deletion_time')->nullable();
    $table->foreignId('deleter_user_id')->nullable()->constrained('users');
    $table->boolean('is_deleted')->default(false);
    $table->string('description');
});

Schema::create('sizes', function (Blueprint $table) {
    $table->id();
    $table->datetime('creation_time')->default(DB::raw('CURRENT_TIMESTAMP'));
    $table->foreignId('creator_user_id')->nullable()->constrained('users');
    $table->datetime('last_modification_time')->nullable();
    $table->foreignId('last_modifier_user_id')->nullable()->constrained('users');
    $table->datetime('deletion_time')->nullable();
    $table->foreignId('deleter_user_id')->nullable()->constrained('users');
    $table->boolean('is_deleted')->default(false);
    $table->foreignId('size_type_id')->constrained('size_types');
    $table->string('description');
});

Schema::create('colors', function (Blueprint $table) {
    $table->id();
    $table->datetime('creation_time')->default(DB::raw('CURRENT_TIMESTAMP'));
    $table->foreignId('creator_user_id')->nullable()->constrained('users');
    $table->datetime('last_modification_time')->nullable();
    $table->foreignId('last_modifier_user_id')->nullable()->constrained('users');
    $table->datetime('deletion_time')->nullable();
    $table->foreignId('deleter_user_id')->nullable()->constrained('users');
    $table->boolean('is_deleted')->default(false);
    $table->string('description');
    $table->string('hash')->nullable();
});

Schema::create('product_size', function (Blueprint $table) {
    $table->id();
    $table->foreignId('product_id')->constrained('products')->onDelete('cascade');
    $table->foreignId('size_id')->constrained('sizes')->onDelete('cascade');
    $table->string('barcode')->nullable();
    $table->integer('stock');
    $table->float('purchase_price')->nullable();
    $table->float('sale_price')->nullable();
    $table->float('min_sale_price')->nullable();
});

Schema::create('product_size_color', function (Blueprint $table) {
    $table->foreignId('product_size_id')->constrained('product_size')->onDelete('cascade');
    $table->foreignId('color_id')->constrained('colors')->onDelete('cascade');
    $table->primary(['product_size_id', 'color_id']);
    $table->integer('stock');
});
