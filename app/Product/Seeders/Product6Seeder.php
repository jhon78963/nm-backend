<?php

namespace App\Product\Seeders;

use App\Product\Models\Product;
use App\Product\Models\ProductSize;
use Illuminate\Database\Seeder;

class Product6Seeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $product = new Product();
        $product->name = 'yoguer de hombre';
        $product->gender_id = 1;
        $product->save();

        $productSize = new ProductSize();
        $productSize->product_id = $product->id;
        $productSize->size_id = 1;
        $productSize->stock = 9;
        $productSize->purchase_price = 40;
        $productSize->sale_price = 55;
        $productSize->min_sale_price = 50;
        $productSize->save();
    }
}
