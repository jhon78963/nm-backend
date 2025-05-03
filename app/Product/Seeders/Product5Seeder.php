<?php

namespace App\Product\Seeders;

use App\Product\Models\Product;
use App\Product\Models\ProductSize;
use Illuminate\Database\Seeder;

class Product5Seeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $product = new Product();
        $product->name = 'pantalones strech semi pitillo';
        $product->gender_id = 1;
        $product->save();

        $productSize = new ProductSize();
        $productSize->product_id = $product->id;
        $productSize->size_id = 9;
        $productSize->stock = 11;
        $productSize->purchase_price = 40;
        $productSize->sale_price = 55;
        $productSize->min_sale_price = 50;
        $productSize->save();

        $productSize = new ProductSize();
        $productSize->product_id = $product->id;
        $productSize->size_id = 10;
        $productSize->stock = 13;
        $productSize->purchase_price = 40;
        $productSize->sale_price = 55;
        $productSize->min_sale_price = 50;
        $productSize->save();

        $productSize = new ProductSize();
        $productSize->product_id = $product->id;
        $productSize->size_id = 11;
        $productSize->stock = 7;
        $productSize->purchase_price = 40;
        $productSize->sale_price = 55;
        $productSize->min_sale_price = 50;
        $productSize->save();

        $productSize = new ProductSize();
        $productSize->product_id = $product->id;
        $productSize->size_id = 12;
        $productSize->stock = 4;
        $productSize->purchase_price = 40;
        $productSize->sale_price = 55;
        $productSize->min_sale_price = 50;
        $productSize->save();

        $productSize = new ProductSize();
        $productSize->product_id = $product->id;
        $productSize->size_id = 14;
        $productSize->stock = 4;
        $productSize->purchase_price = 40;
        $productSize->sale_price = 55;
        $productSize->min_sale_price = 50;
        $productSize->save();
    }
}
