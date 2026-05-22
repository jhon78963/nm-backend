<?php

namespace App\Inventory\Product\Controllers;

use App\Inventory\Product\Requests\EcommerceCatalogRequest;
use App\Inventory\Product\Resources\ProductEcommerceResource;
use App\Inventory\Product\Support\EcommerceCatalogScope;
use App\Inventory\Product\Support\EcommerceStoreResolver;
use App\Inventory\Warehouse\Models\Warehouse;
use App\Shared\Foundation\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * API pública de catálogo web (legacy Multikart / storefront externo).
 *
 * Requiere `?store={catalog_public_token}` por tienda. No hay listado global multi-tenant.
 * El panel nm-frontend no consume este endpoint; gestiona ecommerce vía rutas autenticadas.
 */
class ProductEcommerceController extends Controller
{
    use EcommerceCatalogScope;

    private const INDEX_LIMIT = 200;

    public function index(EcommerceCatalogRequest $request): JsonResponse
    {
        $warehouse = EcommerceStoreResolver::resolveWarehouse($request->validated('store'));
        $limit = min(
            max((int) $request->query('limit', self::INDEX_LIMIT), 1),
            self::INDEX_LIMIT,
        );

        $products = $this->ecommerceProductQuery((int) $warehouse->id)
            ->with($this->ecommerceWith((int) $warehouse->id))
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        return ProductEcommerceResource::collection($products)
            ->additional(['store' => $this->storeMeta($warehouse)])
            ->response();
    }

    public function show(EcommerceCatalogRequest $request, int $id): JsonResponse
    {
        $warehouse = EcommerceStoreResolver::resolveWarehouse($request->validated('store'));

        $product = $this->ecommerceProductQuery((int) $warehouse->id)
            ->whereKey($id)
            ->with($this->ecommerceWith((int) $warehouse->id))
            ->firstOrFail();

        return (new ProductEcommerceResource($product))
            ->additional(['store' => $this->storeMeta($warehouse)])
            ->response();
    }

    /**
     * @return array{name: string, slug: string}
     */
    private function storeMeta(Warehouse $warehouse): array
    {
        return [
            'name' => $warehouse->name,
            'slug' => \Illuminate\Support\Str::slug($warehouse->name),
        ];
    }
}
