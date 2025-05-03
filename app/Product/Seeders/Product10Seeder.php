<?php

namespace App\Product\Seeders;

use App\Product\Models\Product;
use App\Product\Models\ProductSize;
use Illuminate\Database\Seeder;

class Product10Seeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $product = new Product();
        $product->name = 'polos con cuello de hombre';
        $product->gender_id = 1;
        $product->save();

        $productSize = new ProductSize();
        $productSize->product_id = $product->id;
        $productSize->size_id = 3;
        $productSize->stock = 16;
        $productSize->purchase_price = 20;
        $productSize->sale_price = 28;
        $productSize->min_sale_price = 25;
        $productSize->save();

        $productSize = new ProductSize();
        $productSize->product_id = $product->id;
        $productSize->size_id = 4;
        $productSize->stock = 12;
        $productSize->purchase_price = 20;
        $productSize->sale_price = 28;
        $productSize->min_sale_price = 25;
        $productSize->save();

        $productSize = new ProductSize();
        $productSize->product_id = $product->id;
        $productSize->size_id = 5;
        $productSize->stock = 9;
        $productSize->purchase_price = 20;
        $productSize->sale_price = 28;
        $productSize->min_sale_price = 25;
        $productSize->save();

        $productSize = new ProductSize();
        $productSize->product_id = $product->id;
        $productSize->size_id = 6;
        $productSize->stock = 8;
        $productSize->purchase_price = 20;
        $productSize->sale_price = 28;
        $productSize->min_sale_price = 25;
        $productSize->save();
    }
}
