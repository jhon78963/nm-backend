<?php

namespace App\Sales\Controllers;

use App\Directory\Customer\Services\CustomerService;
use App\Inventory\Product\Services\ProductService;
use App\Sales\Models\Sale;
use App\Sales\Services\SaleService;
use App\Shared\Foundation\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SaleController extends Controller
{
    public function __construct(
        protected CustomerService $customerService,
        protected ProductService $productService,
        protected SaleService $saleService
    ) {
    }

    public function searchProduct(Request $request): JsonResponse
    {
        $sku = $request->query('sku');
        if (!$sku)
            return response()->json(['error' => 'SKU requerido'], 400);
        $product = $this->productService->findBySkuForPos($sku);
        if (!$product) {
            return response()->json(['message' => 'Producto no encontrado'], 404);
        }
        return response()->json($product);
    }

    public function searchCustomer(Request $request): JsonResponse
    {
        $dni = $request->query('dni');
        if (!$dni)
            return response()->json(['error' => 'DNI requerido'], 400);
        $customer = $this->customerService->findOrCreateByDoc($dni);
        return response()->json($customer);
    }

    public function checkout(Request $request): JsonResponse
    {
        // Validamos la estructura
        $data = $request->validate([
            'customer.id' => 'nullable',
            'total' => 'required|numeric',
            'items' => 'required|array|min:1',
            'items.*.color.product_size_id' => 'required|integer',
            'items.*.color.color_id' => 'required|integer',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unitPrice' => 'required|numeric',
            'items.*.total' => 'required|numeric',
        ]);

        try {
            // Preparamos la data plana para el servicio
            $serviceData = [
                'customer_id' => $data['customer']['id'] ?? null,
                'total' => $data['total'],
                'items' => collect($data['items'])->map(function ($i) {
                    return [
                        'product_size_id' => $i['color']['product_size_id'],
                        'color_id' => $i['color']['color_id'],
                        'quantity' => $i['quantity'],
                        'unit_price' => $i['unitPrice'],
                        'total' => $i['total']
                    ];
                })->toArray()
            ];

            $sale = $this->saleService->processPosSale($serviceData);

            return response()->json([
                'success' => true,
                'sale_id' => $sale->id,
                'message' => 'Venta registrada correctamente'
            ]);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function printTicket($saleId)
    {
        $sale = Sale::with(['details', 'customer'])
            ->where('id', $saleId)
            ->firstOrFail();
        return view('pos.ticket', compact('sale'));
    }
}
