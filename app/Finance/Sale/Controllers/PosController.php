<?php

namespace App\Finance\Sale\Controllers;

use App\Directory\Customer\Services\CustomerService;
use App\Finance\Sale\Models\Sale;
use App\Finance\Sale\Requests\CheckoutPosRequest;
use App\Finance\Sale\Requests\SearchCustomerDocRequest;
use App\Finance\Sale\Requests\SearchProductSkuRequest;
use App\Finance\Sale\Services\SaleService;
use App\Inventory\Product\Services\ProductService;
use App\Shared\Foundation\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;
use Throwable;

class PosController extends Controller
{
    public function __construct(
        protected CustomerService $customerService,
        protected ProductService $productService,
        protected SaleService $saleService,
    ) {
    }

    public function searchProduct(SearchProductSkuRequest $request): JsonResponse
    {
        $sku = $request->validated()['sku'];
        $product = $this->productService->findBySkuForPos($sku);
        if (!$product) {
            return response()->json(['message' => 'Producto no encontrado'], 404);
        }

        return response()->json($product);
    }

    public function searchCustomer(SearchCustomerDocRequest $request): JsonResponse
    {
        $dni = $request->validated()['dni'];
        $customer = $this->customerService->findOrCreateByDoc($dni);

        return response()->json($customer);
    }

    public function checkout(CheckoutPosRequest $request): JsonResponse
    {
        $data = $request->validated();

        try {
            $serviceData = [
                'customer_id' => data_get($data, 'customer.id'),
                'total' => $data['total'],
                'payments' => $data['payments'],
                'items' => collect($data['items'])->map(function ($i) {
                    return [
                        'product_size_id' => $i['color']['product_size_id'],
                        'color_id' => $i['color']['color_id'],
                        'quantity' => $i['quantity'],
                        'unit_price' => $i['unitPrice'],
                        'total' => $i['total'],
                    ];
                })->toArray(),
            ];

            $sale = $this->saleService->processPosSale($serviceData);

            return response()->json([
                'success' => true,
                'sale_id' => $sale->id,
                'message' => 'Venta registrada correctamente',
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function printTicket(int $saleId): View
    {
        $sale = Sale::query()
            ->with(['details', 'customer'])
            ->where('id', $saleId)
            ->firstOrFail();

        return view('pos.ticket', compact('sale'));
    }
}
