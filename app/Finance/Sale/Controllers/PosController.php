<?php

namespace App\Finance\Sale\Controllers;

use App\Directory\Customer\Services\CustomerService;
use App\Finance\Sale\Models\Sale;
use App\Finance\Sale\Requests\CheckoutPosRequest;
use App\Finance\Sale\Requests\SearchCustomerDocRequest;
use App\Finance\Sale\Requests\SearchProductSkuRequest;
use App\Administration\Tenant\Models\TenantSetting;
use App\Finance\Sale\Services\ElectronicDocumentService;
use App\Finance\Sale\Services\SaleService;
use App\Finance\Sale\Services\SunatQrService;
use App\Finance\Sale\Support\SaleAccessScope;
use App\Inventory\Product\Services\ProductService;
use App\Shared\Foundation\Controllers\Controller;
use App\Shared\Foundation\Support\SafeLogContext;
use App\Shared\Foundation\Exceptions\UserWarehouseNotAssignedException;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class PosController extends Controller
{
    public function __construct(
        protected CustomerService            $customerService,
        protected ProductService             $productService,
        protected SaleService                $saleService,
        protected ElectronicDocumentService  $electronicDocumentService,
        protected SunatQrService             $sunatQrService,
    ) {
    }

    public function searchProduct(SearchProductSkuRequest $request): JsonResponse
    {
        $sku = $request->validated()['sku'];
        $product = $this->productService->findBySkuForPos($sku);
        if (!$product) {
            abort(404, 'Producto no encontrado');
        }

        return response()->json($product);
    }

    public function searchCustomer(SearchCustomerDocRequest $request): JsonResponse
    {
        $dni = $request->validated()['dni'];

        try {
            $customer = $this->customerService->findOrCreateByDoc($dni);

            if ($customer === null) {
                return response()->json([
                    'success' => false,
                    'code' => 'DOC_NOT_FOUND',
                    'message' => 'No se encontró un cliente registrado con ese documento.',
                ], 404);
            }

            return response()->json($customer);
        } catch (Exception $e) {
            return match ($e->getMessage()) {
                'SUNAT_TIMEOUT', 'SUNAT_UNAVAILABLE' => response()->json([
                    'success' => false,
                    'code' => $e->getMessage(),
                    'message' => 'El servicio de SUNAT está inestable. Reintente en un momento.',
                ], 503),
                'DOC_NOT_FOUND' => response()->json([
                    'success' => false,
                    'code' => 'DOC_NOT_FOUND',
                    'message' => 'El documento no está registrado en SUNAT/RENIEC.',
                ], 404),
                default => response()->json([
                    'success' => false,
                    'code' => 'SUNAT_LOOKUP_FAILED',
                    'message' => 'No se pudo consultar el documento. Intente nuevamente.',
                ], 500),
            };
        }
    }

    public function checkout(CheckoutPosRequest $request): JsonResponse
    {
        $data = $request->validated();

        try {
            $serviceData = [
                'customer_id'   => data_get($data, 'customer.id'),
                'document_type' => $data['document_type'],
                'serie'         => $data['serie'] ?? null,
                'payments'      => $data['payments'],
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

            // sendDocument() corre FUERA de la TX de la venta: SOAP no participa
            // en el commit. Si SUNAT falla la venta queda PENDING y se reintenta.
            if ($sale->sunat_status === 'PENDING') {
                try {
                    $this->electronicDocumentService->sendDocument($sale);
                } catch (Throwable $e) {
                    Log::error('SUNAT send failed after checkout', [
                        'sale_id' => $sale->id,
                        'error'   => $e->getMessage(),
                    ]);
                    // La venta fue registrada correctamente; el fallo de SUNAT
                    // no revierte la venta — se informa como advertencia.
                }
            }

            return response()->json([
                'success'      => true,
                'sale_id'      => $sale->id,
                'ticket_url'   => $this->ticketPrintUrl((int) $sale->id),
                'sunat_status' => $sale->sunat_status,
                'invoice_number' => $sale->full_invoice_number,
                'message'      => 'Venta registrada correctamente',
            ]);
        } catch (ValidationException $e) {
            return \App\Shared\Foundation\Exceptions\ApiExceptionRenderer::render($e, request())
                ?? response()->json(['success' => false, 'message' => 'Datos de venta no válidos.', 'error' => 'VALIDATION_ERROR'], 422);
        } catch (UserWarehouseNotAssignedException $e) {
            return $this->apiErrorResponse($e, 403);
        } catch (Throwable $e) {
            Log::error('POS checkout failed', SafeLogContext::fromThrowable($e));

            return $this->apiErrorResponse($e, 500, [
                'message' => 'Ocurrió un error al procesar la venta.',
            ]);
        }
    }

    public function ticketUrl(int $saleId): JsonResponse
    {
        $this->findTicketSale($saleId);

        return response()->json([
            'ticket_url' => $this->ticketPrintUrl($saleId),
        ]);
    }

    public function printTicket(int $saleId): View
    {
        $sale = $this->findTicketSale($saleId);

        $qrSvg   = $this->sunatQrService->generateQrSvg($sale);
        $xmlHash = $this->sunatQrService->getXmlHash($sale);

        // Carga la configuración del tenant dueño del almacén de esta venta.
        // No usa BelongsToWarehouse scope (warehouses es modelo plano).
        $tenantId = \DB::table('warehouses')
            ->where('id', $sale->warehouse_id)
            ->value('tenant_id');

        $tenantSetting = $tenantId
            ? TenantSetting::where('tenant_id', $tenantId)->first()
            : null;

        return view('pos.ticket', compact('sale', 'qrSvg', 'xmlHash', 'tenantSetting'));
    }

    /**
     * Sale usa BelongsToWarehouse → WarehouseScope filtra por almacén del actor autenticado.
     * Super Admin puede cambiar almacén vía X-Warehouse-Id (AuthenticatedUserWarehouseResolver).
     * Sin sesión válida el scope no aplica; la ruta exige auth:sanctum en api.php.
     */
    private function findTicketSale(int $saleId): Sale
    {
        $query = Sale::query()
            ->with(['details', 'customer', 'payments'])
            ->where('is_deleted', false)
            ->whereKey($saleId);

        if (SaleAccessScope::restrictsToCurrentMonth()) {
            SaleAccessScope::applyCurrentMonthFilter($query);
        }

        return $query->firstOrFail();
    }

    private function ticketPrintUrl(int $saleId): string
    {
        return route('pos.sales.ticket', ['saleId' => $saleId]);
    }
}
