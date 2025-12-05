<?php

namespace App\Sales\Controllers;

// ... imports anteriores ...
use App\Directory\Customer\Services\CustomerService;
use App\Inventory\Product\Services\ProductService;
use App\Sales\Models\Sale;
use App\Sales\Services\SaleService;
use App\Shared\Foundation\Controllers\Controller;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Mike42\Escpos\Printer;
use Mike42\Escpos\EscposImage;
use Mike42\Escpos\PrintConnectors\FilePrintConnector;

class SaleController extends Controller
{
    // ... constructor ...
    public function __construct(
        protected ProductService $productService,
        protected CustomerService $customerService,
        protected SaleService $saleService
    ) {
    }

    // ... searchProduct y searchCustomer igual que antes ...
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

        // Aquí asumo que tienes tu lógica
        $customer = $this->customerService->findOrCreateByDoc($dni);
        // Retornar mock por ahora si no tienes customerService implementado
        return response()->json($customer);
    }

    public function checkout(Request $request): JsonResponse
    {
        // Validamos la estructura
        $data = $request->validate([
            'customer.id' => 'nullable',
            'total' => 'required|numeric',
            'items' => 'required|array|min:1',
            // OJO AQUÍ: Validamos que el color traiga los IDs compuestos que mandamos en ProductService
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
                        // Extraemos los IDs que pusimos en el ProductService ('variantsMap')
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
        // 1. Cargamos la venta con la relación correcta: 'details' y 'customer'
        $sale = Sale::with(['details', 'customer'])
            ->where('id', $saleId)
            ->firstOrFail();

        // 2. Configuración para impresora térmica 80mm
        // [0, 0, 226.77, 1000] => ancho 80mm, alto largo dinámico
        // $customPaper = [0, 0, 226.77, 1000];

        // // 3. Generamos el PDF
        // $pdf = Pdf::loadView('pos.ticket', compact('sale'))
        //     ->setPaper($customPaper, 'portrait');

        return view('pos.ticket', compact('sale'));
    }

    public function getTicketBase64($saleId)
    {
        $sale = Sale::with(['details', 'customer'])->findOrFail($saleId);

        // --- CORRECCIÓN CLAVE ---
        // 1. No generamos PDF (muy pesado). Renderizamos solo el HTML (muy ligero).
        $htmlContent = view('pos.ticket', compact('sale'))->render();

        // 2. Limpiamos basura (BOM) para que RawBT no imprima código fuente
        $htmlContent = str_replace("\xEF\xBB\xBF", '', $htmlContent);
        $htmlContent = str_replace("data:", '', $htmlContent);

        // 3. Aseguramos que tenga la etiqueta DOCTYPE
        if (!str_starts_with(strtolower($htmlContent), '<!doctype html>')) {
            $htmlContent = "<!DOCTYPE html>\n" . $htmlContent;
        }

        // 4. Codificamos el HTML a Base64
        $base64 = base64_encode($htmlContent);

        return response()->json([
            'success' => true,
            'data' => $base64
        ]);
    }

    public function getTicketHtml($saleId)
    {
        $sale = Sale::with(['details', 'customer'])->findOrFail($saleId);

        // Renderizamos la vista a string
        $htmlContent = view('pos.ticket', compact('sale'))->render();

        return response()->json([
            'success' => true,
            'data' => $htmlContent
        ]);
    }
}
