<?php
namespace App\Inventory\Product\Services;

use App\Inventory\Product\Models\Product;
use App\Shared\Foundation\Services\ModelService;

class ProductService extends ModelService
{
    public function __construct(Product $product)
    {
        parent::__construct($product);
    }

    public function discontinueAndDiscard(Product $product): void
    {
        $this->update($product, ['status' => 'DISCONTINUED']);
        $this->delete($product);
    }
}
