<?php

namespace App\Finance\Sale\Controllers;

use App\Directory\Customer\Services\CustomerService;
use App\Inventory\Product\Services\ProductService;
use App\Finance\Sale\Models\Sale;
use App\Finance\Sale\Requests\SaleUpdateRequest;
use App\Finance\Sale\Resources\SaleDetailResource;
use App\Finance\Sale\Resources\SaleResource;
use App\Finance\Sale\Services\SaleService;
use App\Shared\Foundation\Controllers\Controller;
use App\Shared\Foundation\Requests\GetAllRequest;
use App\Shared\Foundation\Resources\GetAllCollection;
use App\Shared\Foundation\Services\SharedService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SaleController extends Controller
{
    public function __construct(
        protected CustomerService $customerService,
        protected ProductService $productService,
        protected SaleService $saleService,
        protected SharedService $sharedService,
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

    public function getMonthlyStats(): JsonResponse
    {
        $stats = $this->saleService->getMonthlyStats();
        return response()->json($stats);
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
        // 1. Validamos la estructura (Esto estaba bien, lo mantenemos)
        $data = $request->validate([
            'customer.id' => 'nullable',
            'total' => 'required|numeric',
            'items' => 'required|array|min:1',
            'items.*.color.product_size_id' => 'required|integer',
            'items.*.color.color_id' => 'required|integer',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unitPrice' => 'required|numeric',
            'items.*.total' => 'required|numeric',
            // Pagos
            'payments' => 'required|array|min:1',
            'payments.*.method' => 'required|string',
            'payments.*.amount' => 'required|numeric',
            'payments.*.reference' => 'nullable|string',
        ]);

        try {
            // 2. Preparamos la data para el servicio
            $serviceData = [
                'customer_id' => $data['customer']['id'] ?? null,
                'total' => $data['total'],

                // --- CORRECCIÓN CLAVE: PASAR LOS PAGOS AL SERVICIO ---
                'payments' => $data['payments'],

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

            // 3. Procesamos
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

    public function delete(Sale $sale): JsonResponse
    {
        return DB::transaction(function () use ($sale): JsonResponse {
            $this->saleService->validate($sale, 'Sale');
            $this->saleService->delete($sale);

            return response()->json(['message' => 'Sale deleted successfully.']);
        });
    }

    public function get(Sale $sale): JsonResponse
    {
        $this->saleService->validate($sale, 'Sale');
        return response()->json(new SaleDetailResource($sale));
    }

    public function getAll(GetAllRequest $request): JsonResponse
    {
        $query = $this->sharedService->query(
            request: $request,
            entityName: 'Finance\\Sale',
            modelName: 'Sale',
            columnSearch: ['id', 'code', 'creation_time', 'status', 'payment_method', 'customer.name'],
            orderBy: 'creation_time',
            orderDir: 'desc',
        );

        return response()->json(new GetAllCollection(
            SaleResource::collection($query['collection']),
            $query['total'],
            $query['pages'],
        ));
    }

    public function update(SaleUpdateRequest $request, Sale $sale): JsonResponse
    {
        return DB::transaction(function () use ($request, $sale): JsonResponse {
            $this->saleService->validate($sale, 'Sale');
            $data = $this->sharedService->convertCamelToSnake($request->validated());
            $this->saleService->update($sale, $data);

            return response()->json(['message' => 'Sale updated.'], 200);
        });
    }
}
