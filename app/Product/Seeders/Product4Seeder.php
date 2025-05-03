<?php

namespace App\Product\Seeders;

use App\Product\Models\Product;
use App\Product\Models\ProductSize;
use Illuminate\Database\Seeder;

class Product4Seeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $product = new Product();
        $product->name = 'ofertas caja';
        $product->gender_id = 4;
        $product->save();

        $productSize = new ProductSize();
        $productSize->product_id = $product->id;
        $productSize->size_id = 1;
        $productSize->stock = 52;
        $productSize->purchase_price = 10;
        $productSize->sale_price = 10;
        $productSize->min_sale_price = 10;
        $productSize->save();
    }
}
