<?php

namespace App\Product\Seeders;

use App\Product\Models\Product;
use App\Product\Models\ProductSize;
use Illuminate\Database\Seeder;

class Product3Seeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $product = new Product();
        $product->name = 'conjuntos deportivos de niños';
        $product->gender_id = 3;
        $product->save();

        $productSize = new ProductSize();
        $productSize->product_id = $product->id;
        $productSize->size_id = 19;
        $productSize->stock = 14;
        $productSize->purchase_price = 13;
        $productSize->sale_price = 28;
        $productSize->min_sale_price = 25;
        $productSize->save();

        $productSize = new ProductSize();
        $productSize->product_id = $product->id;
        $productSize->size_id = 22;
        $productSize->stock = 3;
        $productSize->purchase_price = 13;
        $productSize->sale_price = 28;
        $productSize->min_sale_price = 25;
        $productSize->save();

        $productSize = new ProductSize();
        $productSize->product_id = $product->id;
        $productSize->size_id = 24;
        $productSize->stock = 6;
        $productSize->purchase_price = 13;
        $productSize->sale_price = 28;
        $productSize->min_sale_price = 25;
        $productSize->save();
    }
}
