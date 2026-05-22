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
use App\Shared\Foundation\Exceptions\UserWarehouseNotAssignedException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class PosController extends Controller
{
    private const TICKET_URL_TTL_MINUTES = 15;

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
                'payments' => $data['payments'],
                'items' => collect($data['items'])->map(function ($i) {
                    return [
                        'product_size_id' => $i['color']['product_size_id'],
                        'color_id' => $i['color']['color_id'],
                        'quantity' => $i['quantity'],
                        'unit_price' => $i['unitPrice'],
                    ];
                })->toArray(),
            ];

            $sale = $this->saleService->processPosSale($serviceData);

            return response()->json([
                'success' => true,
                'sale_id' => $sale->id,
                'ticket_url' => $this->signedTicketUrl((int) $sale->id),
                'message' => 'Venta registrada correctamente',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => collect($e->errors())->flatten()->first() ?? 'Datos de venta no válidos.',
                'errors' => $e->errors(),
            ], 422);
        } catch (UserWarehouseNotAssignedException $e) {
            return $this->apiErrorResponse($e, 403, ['success' => false]);
        } catch (Throwable $e) {
            Log::error('POS checkout failed', [
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);

            return $this->apiErrorResponse($e, 500, [
                'success' => false,
                'message' => 'Ocurrió un error al procesar la venta.',
            ]);
        }
    }

    public function ticketUrl(int $saleId): JsonResponse
    {
        $this->findTicketSale($saleId);

        return response()->json([
            'ticket_url' => $this->signedTicketUrl($saleId),
        ]);
    }

    public function printTicket(Request $request, int $saleId): View
    {
        if (! $request->hasValidSignature()) {
            abort(403, 'Enlace de ticket inválido o expirado.');
        }

        $sale = $this->findTicketSale($saleId);

        return view('pos.ticket', compact('sale'));
    }

    private function findTicketSale(int $saleId): Sale
    {
        return Sale::query()
            ->with(['details', 'customer'])
            ->where('is_deleted', false)
            ->whereKey($saleId)
            ->firstOrFail();
    }

    private function signedTicketUrl(int $saleId): string
    {
        return URL::temporarySignedRoute(
            'pos.sales.ticket',
            now()->addMinutes(self::TICKET_URL_TTL_MINUTES),
            ['saleId' => $saleId],
        );
    }
}
