<?php

namespace App\Product\Seeders;

use App\Product\Models\Product;
use App\Product\Models\ProductSize;
use Illuminate\Database\Seeder;

class Product11Seeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $product = new Product();
        $product->name = 'polo con cuello licrado';
        $product->gender_id = 1;
        $product->save();

        $productSize = new ProductSize();
        $productSize->product_id = $product->id;
        $productSize->size_id = 1;
        $productSize->stock = 13;
        $productSize->purchase_price = 20;
        $productSize->sale_price = 32;
        $productSize->min_sale_price = 30;
        $productSize->save();
    }
}
