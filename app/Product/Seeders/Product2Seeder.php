<?php

namespace App\Product\Seeders;

use App\Product\Models\Product;
use App\Product\Models\ProductSize;
use Illuminate\Database\Seeder;

class Product2Seeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $product = new Product();
        $product->name = 'pantalones jeans de niño';
        $product->gender_id = 3;
        $product->save();

        $productSize = new ProductSize();
        $productSize->product_id = $product->id;
        $productSize->size_id = 17;
        $productSize->stock = 3;
        $productSize->purchase_price = 25;
        $productSize->sale_price = 38;
        $productSize->min_sale_price = 35;
        $productSize->save();

        $productSize = new ProductSize();
        $productSize->product_id = $product->id;
        $productSize->size_id = 18;
        $productSize->stock = 3;
        $productSize->purchase_price = 25;
        $productSize->sale_price = 38;
        $productSize->min_sale_price = 35;
        $productSize->save();

        $productSize = new ProductSize();
        $productSize->product_id = $product->id;
        $productSize->size_id = 19;
        $productSize->stock = 3;
        $productSize->purchase_price = 25;
        $productSize->sale_price = 38;
        $productSize->min_sale_price = 35;
        $productSize->save();

        $productSize = new ProductSize();
        $productSize->product_id = $product->id;
        $productSize->size_id = 20;
        $productSize->stock = 3;
        $productSize->purchase_price = 25;
        $productSize->sale_price = 38;
        $productSize->min_sale_price = 35;
        $productSize->save();

        $productSize = new ProductSize();
        $productSize->product_id = $product->id;
        $productSize->size_id = 21;
        $productSize->stock = 3;
        $productSize->purchase_price = 25;
        $productSize->sale_price = 38;
        $productSize->min_sale_price = 35;
        $productSize->save();

        $productSize = new ProductSize();
        $productSize->product_id = $product->id;
        $productSize->size_id = 22;
        $productSize->stock = 3;
        $productSize->purchase_price = 25;
        $productSize->sale_price = 38;
        $productSize->min_sale_price = 35;
        $productSize->save();

        $productSize = new ProductSize();
        $productSize->product_id = $product->id;
        $productSize->size_id = 23;
        $productSize->stock = 3;
        $productSize->purchase_price = 25;
        $productSize->sale_price = 38;
        $productSize->min_sale_price = 35;
        $productSize->save();
    }
}
