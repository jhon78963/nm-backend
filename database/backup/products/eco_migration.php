<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;


Schema::create('attributes', function (Blueprint $table) {
    $table->id();
    $table->string('name')->nullable();
    $table->string('style')->nullable();
    $table->string('slug')->nullable();
    $table->integer('status')->default(1);
    $table->bigInteger('created_by_id')->unsigned();
    $table->timestamps();
    $table->softDeletes();

    $table->foreign('created_by_id')->references('id')->on('users')->onDelete('cascade');
});

Schema::create('attribute_values', function (Blueprint $table) {
    $table->id();
    $table->string('value')->nullable();
    $table->string('hex_color')->nullable();
    $table->string('slug')->nullable();
    $table->bigInteger('attribute_id')->unsigned()->nullable();
    $table->bigInteger('created_by_id')->unsigned();
    $table->timestamps();
    $table->softDeletes();

    $table->foreign('attribute_id')->references('id')->on('attributes')->onDelete('cascade');
    $table->foreign('created_by_id')->references('id')->on('users')->onDelete('cascade');
});

Schema::create('categories', function (Blueprint $table) {
    $table->id();
    $table->string('name')->nullable();
    $table->string('slug')->nullable();
    $table->string('meta_title')->nullable();
    $table->longText('meta_description')->nullable();
    $table->longText('description')->nullable();
    $table->unsignedBigInteger('category_meta_image_id')->nullable();
    $table->unsignedBigInteger('category_image_id')->nullable();
    $table->unsignedBigInteger('category_icon_id')->nullable();
    $table->integer('status')->default(1);
    $table->string('type')->default('post');
    $table->decimal('commission_rate', 8, 2)->default(0.0)->nullable();
    $table->unsignedBigInteger('parent_id')->nullable();
    $table->integer('is_allow_all_zone')->default(0)->nullable();
    $table->bigInteger('created_by_id')->unsigned()->nullable();
    $table->timestamps();
    $table->softDeletes();

    $table->foreign('category_image_id')->references('id')->on('attachments')->onDelete('cascade');
    $table->foreign('category_meta_image_id')->references('id')->on('attachments')->onDelete('cascade');
    $table->foreign('category_icon_id')->references('id')->on('attachments')->onDelete('cascade');
    $table->foreign('parent_id')->references('id')->on('categories')->onDelete('cascade');
    $table->foreign('created_by_id')->references('id')->on('users')->onDelete('cascade');
});

Schema::create('category_zones', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('category_id');
    $table->unsignedBigInteger('zone_id');

    $table->foreign('category_id')->references('id')->on('categories')->onDelete('cascade')->nullable();
    $table->foreign('zone_id')->references('id')->on('zones')->onDelete('cascade')->nullable();
});

Schema::create('exclude_zone_categories', function (Blueprint $table) {
    $table->id();
    $table->bigInteger('zone_id')->unsigned()->nullable();
    $table->bigInteger('category_id')->unsigned()->nullable();

    $table->foreign('zone_id')->references('id')->on('zones')->onDelete('cascade');
    $table->foreign('category_id')->references('id')->on('categories')->onDelete('cascade');
});

Schema::create('tags', function (Blueprint $table) {
    $table->id();
    $table->string('name')->nullable();
    $table->string('slug')->nullable();
    $table->string('type')->default('post');
    $table->longText('description')->nullable();
    $table->bigInteger('created_by_id')->unsigned();
    $table->integer('status')->default(1)->nullable();
    $table->timestamps();
    $table->softDeletes();

    $table->foreign('created_by_id')->references('id')->on('users')->onDelete('cascade');
});

Schema::create('stores', function (Blueprint $table) {
    $table->id();
    $table->string('store_name')->nullable();
    $table->longText('description')->nullable();
    $table->string('slug')->nullable();
    $table->bigInteger('store_logo_id')->unsigned()->nullable();
    $table->bigInteger('store_cover_id')->unsigned()->nullable();
    $table->bigInteger('country_id')->unsigned()->nullable();
    $table->bigInteger('state_id')->unsigned()->nullable();
    $table->string('city')->nullable();
    $table->string('address')->nullable();
    $table->string('pincode')->nullable();
    $table->string('facebook')->nullable();
    $table->string('twitter')->nullable();
    $table->string('instagram')->nullable();
    $table->string('youtube')->nullable();
    $table->string('pinterest')->nullable();
    $table->integer('hide_vendor_email')->default(1)->nullable();
    $table->integer('hide_vendor_phone')->default(1)->nullable();
    $table->bigInteger('vendor_id')->unsigned()->nullable();
    $table->bigInteger('created_by_id')->unsigned()->nullable();
    $table->integer('status')->default(1);
    $table->integer('is_approved')->default(1)->nullable();
    $table->timestamps();
    $table->softDeletes();

    $table->foreign('store_logo_id')->references('id')->on('attachments')->onDelete('cascade');
    $table->foreign('store_cover_id')->references('id')->on('attachments')->onDelete('cascade');
    $table->foreign('country_id')->references('id')->on('countries')->onDelete('cascade');
    $table->foreign('state_id')->references('id')->on('states')->onDelete('cascade');
    $table->foreign('vendor_id')->references('id')->on('users')->onDelete('cascade');
    $table->foreign('created_by_id')->references('id')->on('users')->onDelete('cascade');
});

Schema::create('brands', function (Blueprint $table) {
    $table->id();
    $table->string('name')->nullable();
    $table->string('slug')->nullable();
    $table->unsignedBigInteger('brand_image_id')->nullable();
    $table->unsignedBigInteger('brand_meta_image_id')->nullable();
    $table->string('meta_title')->nullable();
    $table->longText('meta_description')->nullable();
    $table->unsignedBigInteger('created_by_id')->nullable();
    $table->integer('status')->default(1);
    $table->timestamps();
    $table->softDeletes();

    $table->foreign('brand_image_id')->references('id')->on('attachments')->onDelete('cascade');
    $table->foreign('brand_meta_image_id')->references('id')->on('attachments')->onDelete('cascade');
    $table->foreign('created_by_id')->references('id')->on('users')->onDelete('cascade');
});

Schema::create('products', function (Blueprint $table) {
    $table->id();
    $table->string('name')->nullable();
    $table->longText('short_description')->nullable();
    $table->longText('description')->nullable();
    $table->enum('type', ['simple', 'classified'])->nullable();
    $table->string('unit')->nullable();
    $table->integer('weight')->nullable();
    $table->integer('quantity')->nullable();
    $table->decimal('price')->nullable();
    $table->decimal('sale_price')->nullable();
    $table->decimal('discount')->nullable();
    $table->integer('is_featured')->default(0)->nullable();
    $table->integer('shipping_days')->default(0)->nullable();
    $table->integer('is_cod')->default(0);
    $table->integer('is_free_shipping')->default(0)->nullable();
    $table->integer('is_sale_enable')->default(0)->nullable();
    $table->integer('is_return')->default(0)->nullable();
    $table->integer('is_trending')->default(0)->nullable();
    $table->integer('is_approved')->default(1)->nullable();
    $table->integer('is_external')->default(0)->nullable();
    $table->string('external_url')->nullable();
    $table->string('external_button_text')->nullable();
    $table->string('sale_starts_at')->nullable();
    $table->string('sale_expired_at')->nullable();
    $table->string('sku')->nullable();
    $table->integer('is_random_related_products')->default(0)->nullable();
    $table->enum('stock_status', ['in_stock', 'out_of_stock'])->nullable();
    $table->string('meta_title')->nullable();
    $table->longText('meta_description')->nullable();
    $table->unsignedBigInteger('product_thumbnail_id')->nullable();
    $table->unsignedBigInteger('product_meta_image_id')->nullable();
    $table->unsignedBigInteger('size_chart_image_id')->nullable();
    $table->string('estimated_delivery_text')->nullable();
    $table->longText('return_policy_text')->nullable();
    $table->integer('safe_checkout')->default(1)->nullable();
    $table->integer('secure_checkout')->default(1)->nullable();
    $table->integer('social_share')->default(1)->nullable();
    $table->integer('encourage_order')->default(1)->nullable();
    $table->integer('encourage_view')->default(1)->nullable();
    $table->string('slug')->nullable();
    $table->integer('status')->default(1);
    $table->unsignedBigInteger('store_id')->nullable();

    $table->string('external_details')->nullable();
    $table->string('visible_time')->nullable();
    $table->longText('read_button_text')->nullable();
    $table->longText('read_document_id')->nullable();
    $table->unsignedBigInteger('publication_id')->nullable();

    $table->unsignedBigInteger('created_by_id')->nullable();
    $table->unsignedBigInteger('tax_id')->nullable();
    $table->enum('preview_type', ['audio', 'video', 'url'])->nullable();
    $table->enum('product_type', ['physical', 'digital', 'external']);
    $table->string('separator')->nullable();
    $table->integer('is_licensable')->default(0)->nullable();
    $table->string('preview_url')->nullable();
    $table->integer('watermark')->default(0);
    $table->string('watermark_position')->nullable();
    $table->unsignedBigInteger('brand_id')->nullable();
    $table->unsignedBigInteger('watermark_image_id')->nullable();
    $table->enum('wholesale_price_type', ['fixed', 'percentage'])->nullable();
    $table->integer('is_licensekey_auto')->default(0)->nullable();
    $table->unsignedBigInteger('preview_audio_file_id')->nullable();
    $table->unsignedBigInteger('preview_video_file_id')->nullable();

    $table->softDeletes();
    $table->timestamps();

    $table->foreign('brand_id')->references('id')->on('brands')->onDelete('cascade');
    $table->foreign('product_thumbnail_id')->references('id')->on('attachments')->onDelete('cascade');
    $table->foreign('size_chart_image_id')->references('id')->on('attachments')->onDelete('cascade');
    $table->foreign('product_meta_image_id')->references('id')->on('attachments')->onDelete('cascade');
    $table->foreign('tax_id')->references('id')->on('taxes')->onDelete('cascade');
    $table->foreign('store_id')->references('id')->on('stores')->onDelete('cascade');
    $table->foreign('created_by_id')->references('id')->on('users')->onDelete('cascade');
    $table->foreign('watermark_image_id')->references('id')->on('attachments')->onDelete('cascade');
});

Schema::create('wholesales', function (Blueprint $table) {
    $table->id();
    $table->integer('min_qty')->nullable();
    $table->integer('max_qty')->nullable();
    $table->integer('value')->nullable();
    $table->unsignedBigInteger('product_id')->nullable();
    $table->timestamps();
    $table->softDeletes();

    $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');
});

Schema::create('product_images', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('product_id')->nullable();
    $table->unsignedBigInteger('attachment_id')->nullable();
    $table->softDeletes();
    $table->timestamps();

    $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade')->nullable();
    $table->foreign('attachment_id')->references('id')->on('attachments')->onDelete('cascade')->nullable();
});

Schema::create('product_categories', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('product_id');
    $table->unsignedBigInteger('category_id');
    $table->softDeletes();
    $table->timestamps();

    $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade')->nullable();
    $table->foreign('category_id')->references('id')->on('categories')->onDelete('cascade')->nullable();
});

Schema::create('product_tags', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('tag_id');
    $table->unsignedBigInteger('product_id');
    $table->softDeletes();
    $table->timestamps();

    $table->foreign('tag_id')->references('id')->on('tags')->onDelete('cascade')->nullable();
    $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade')->nullable();
});

Schema::create('product_attributes', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('attribute_id');
    $table->unsignedBigInteger('product_id');
    $table->softDeletes();
    $table->timestamps();

    $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade')->nullable();
    $table->foreign('attribute_id')->references('id')->on('attributes')->onDelete('cascade')->nullable();
});

Schema::create('variations', function (Blueprint $table) {
    $table->id();
    $table->string('name')->nullable();
    $table->decimal('price', 8, 2)->nullable();
    $table->integer('quantity')->nullable();
    $table->enum('stock_status', ['in_stock', 'out_of_stock', 'coming_soon'])->nullable();
    $table->decimal('sale_price', 8, 2)->nullable();
    $table->decimal('discount', 8, 2)->nullable();
    $table->string('sku')->nullable();
    $table->integer('status')->default(1);
    $table->json('variation_options')->nullable();
    $table->string('preview_url')->nullable();
    $table->string('separator')->nullable();
    $table->integer('is_licensable')->nullable();
    $table->integer('is_licensekey_auto')->nullable();
    $table->unsignedBigInteger('variation_image_id')->nullable();
    $table->unsignedBigInteger('product_id')->nullable();
    $table->softDeletes();
    $table->timestamps();

    $table->foreign('variation_image_id')->references('id')->on('attachments')->onDelete('cascade')->nullable();
    $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade')->nullable();
});

Schema::create('variation_attribute_values', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('attribute_value_id');
    $table->unsignedBigInteger('variation_id');
    $table->softDeletes();
    $table->timestamps();

    $table->foreign('variation_id')->references('id')->on('variations')->onDelete('cascade')->nullable();
    $table->foreign('attribute_value_id')->references('id')->on('attribute_values')->onDelete('cascade')->nullable();
});

Schema::create('variation_images', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('attachment_id');
    $table->unsignedBigInteger('variation_id');
    $table->softDeletes();
    $table->timestamps();

    $table->foreign('variation_id')->references('id')->on('variations')->onDelete('cascade')->nullable();
    $table->foreign('attachment_id')->references('id')->on('attachments')->onDelete('cascade')->nullable();
});

Schema::create('related_products', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('product_id')->nullable();
    $table->unsignedBigInteger('related_product_id')->nullable();
    $table->softDeletes();
    $table->timestamps();

    $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade')->nullable();
    $table->foreign('related_product_id')->references('id')->on('products')->onDelete('cascade')->nullable();
});

Schema::create('cross_sell_products', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('product_id')->nullable();
    $table->unsignedBigInteger('cross_sell_product_id')->nullable();
    $table->softDeletes();
    $table->timestamps();

    $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade')->nullable();
    $table->foreign('cross_sell_product_id')->references('id')->on('products')->onDelete('cascade')->nullable();
});

Schema::create('product_digital_files', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('product_id')->nullable();
    $table->unsignedBigInteger('variation_id')->nullable();
    $table->unsignedBigInteger('attachment_id')->nullable();
    $table->softDeletes();
    $table->timestamps();

    $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade')->nullable();
    $table->foreign('variation_id')->references('id')->on('variations')->onDelete('cascade')->nullable();
    $table->foreign('attachment_id')->references('id')->on('attachments')->onDelete('cascade')->nullable();
});

Schema::create('product_authors', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('author_id');
    $table->unsignedBigInteger('product_id');
    $table->softDeletes();
    $table->timestamps();

    $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade')->nullable();
    $table->foreign('author_id')->references('id')->on('authors')->onDelete('cascade')->nullable();
});
