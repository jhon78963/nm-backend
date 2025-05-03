<?php

namespace App\Product\Seeders;

use App\Product\Models\Product;
use App\Product\Models\ProductSize;
use Illuminate\Database\Seeder;

class Product9Seeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $product = new Product();
        $product->name = 'shorts niño';
        $product->gender_id = 3;
        $product->save();

        $productSize = new ProductSize();
        $productSize->product_id = $product->id;
        $productSize->size_id = 16;
        $productSize->stock = 5;
        $productSize->purchase_price = 15;
        $productSize->sale_price = 28;
        $productSize->min_sale_price = 25;
        $productSize->save();
    }
}
