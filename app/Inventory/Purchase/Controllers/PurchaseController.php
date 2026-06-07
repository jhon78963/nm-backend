<?php

namespace App\Inventory\Purchase\Controllers;

use App\Finance\CashMovement\Models\CashMovement;
use App\Finance\CashMovement\Services\CashflowService;
use App\Inventory\Purchase\Enums\PurchaseStatus;
use App\Inventory\Purchase\Models\Purchase;
use App\Inventory\Purchase\Models\PurchaseLine;
use App\Inventory\Purchase\Requests\PurchaseBulkRequest;
use App\Inventory\Purchase\Requests\PurchaseCancelRequest;
use App\Inventory\Purchase\Requests\PurchaseIndexRequest;
use App\Inventory\Purchase\Requests\PurchaseLineUpdateRequest;
use App\Inventory\Purchase\Requests\PurchaseUpdateRequest;
use App\Inventory\Purchase\Resources\PurchaseDetailResource;
use App\Inventory\Purchase\Resources\PurchaseListResource;
use App\Inventory\Purchase\Services\PurchaseBulkService;
use App\Inventory\Purchase\Services\PurchaseCancellationService;
use App\Inventory\Purchase\Services\PurchaseLineMutationService;
use App\Shared\Foundation\Controllers\Controller;
use App\Shared\Foundation\Resources\GetAllCollection;
use App\Shared\Foundation\Services\SharedService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PurchaseController extends Controller
{
    public function __construct(
        protected PurchaseBulkService $purchaseBulkService,
        protected PurchaseCancellationService $purchaseCancellationService,
        protected PurchaseLineMutationService $purchaseLineMutationService,
        protected SharedService $sharedService,
        protected CashflowService $cashflowService,
    ) {
    }

    public function registerBulk(PurchaseBulkRequest $request): JsonResponse
    {
        $data = $request->validated();

        $id = $this->purchaseBulkService->handle($data);

        // Registrar la salida de caja como Compra de Mercadería (INVENTORY_PURCHASE).
        // Esta categoría queda excluida de los Gastos Operativos del P&L; su impacto
        // contable aparece únicamente en el Costo de Ventas cuando el stock se vende.
        $this->recordPurchaseCashMovement($request, $data, $id);

        return response()->json([
            'message' => 'Compra registrada e inventario actualizado.',
            'purchaseId' => $id,
        ], 201);
    }

    /**
     * Crea el movimiento de egreso en `cash_movements` replicando el patrón
     * exacto de `CashflowService` (upload de imagen a Node.js vía `NodeUploaderService`).
     */
    private function recordPurchaseCashMovement(PurchaseBulkRequest $request, array $data, int $purchaseId): void
    {
        try {
            $totals = $data['totals'] ?? [];
            $grandSubtotal = (float) ($totals['grandSubtotal'] ?? 0);

            if ($grandSubtotal <= 0) {
                return;
            }

            $purchaseBlock = $data['purchase'] ?? [];
            $supplierName  = (string) ($purchaseBlock['supplierName'] ?? 'Proveedor');
            $registeredAt  = isset($purchaseBlock['registeredAt'])
                ? Carbon::parse((string) $purchaseBlock['registeredAt'])->format('Y-m-d H:i:s')
                : now()->format('Y-m-d H:i:s');

            $movementData = [
                'type'           => CashMovement::TYPE_EXPENSE,
                'category'       => CashMovement::CATEGORY_INVENTORY_PURCHASE,
                'amount'         => $grandSubtotal,
                'description'    => "Compra de mercadería - {$supplierName}",
                'payment_method' => $request->input('payment_method', 'CASH'),
                'date'           => $registeredAt,
                'purchase_id'    => $purchaseId,
            ];

            // Delega la subida de comprobantes a Node.js al mismo servicio que usa Gastos.
            $this->cashflowService->registerMovement($movementData, $request->file('images') ?: null);
        } catch (\Throwable $e) {
            // El inventario ya fue comprometido; registramos el error pero no revertimos.
            Log::error('[PurchaseController] No se pudo registrar el movimiento de caja.', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function getAll(PurchaseIndexRequest $request): JsonResponse
    {
        $filters = [];
        if ($request->filled('warehouseId')) {
            $filters['warehouse_id'] = (int) $request->query('warehouseId');
        }
        if ($request->filled('status')) {
            $filters['status'] = (string) $request->query('status');
        }

        $query = $this->sharedService->query(
            $request,
            'Inventory\\Purchase',
            'Purchase',
            ['id', 'supplier_name', 'document_note'],
            $filters,
            null,
            'id',
            'desc',
        );

        $collection = $query['collection'];
        if ($collection instanceof \Illuminate\Database\Eloquent\Collection) {
            $collection->load('warehouse');
        }

        return response()->json(new GetAllCollection(
            PurchaseListResource::collection($collection),
            $query['total'],
            $query['pages'],
        ));
    }

    public function get(Purchase $purchase): JsonResponse
    {
        $this->ensurePurchaseVisible($purchase);

        $purchase->load([
            'lines.product',
            'lines.size',
            'lines.colorDeltas.color',
            'warehouse',
            'cashMovements',
        ]);

        return response()->json(new PurchaseDetailResource($purchase));
    }

    public function update(PurchaseUpdateRequest $request, Purchase $purchase): JsonResponse
    {
        $this->ensurePurchaseVisible($purchase);

        if ($purchase->status !== PurchaseStatus::Active) {
            return response()->json(['message' => 'Solo se pueden editar datos de compras activas.'], 422);
        }

        return DB::transaction(function () use ($request, $purchase): JsonResponse {
            $data = $this->sharedService->convertCamelToSnake($request->validated());
            if (array_key_exists('document_note', $data)) {
                $purchase->document_note = $data['document_note'];
            }
            if (array_key_exists('registered_at', $data) && $data['registered_at'] !== null) {
                $purchase->registered_at = $data['registered_at'];
            }
            $purchase->last_modifier_user_id = Auth::id();
            $purchase->last_modification_time = now();
            $purchase->save();

            return response()->json(['message' => 'Compra actualizada.'], 200);
        });
    }

    public function cancel(PurchaseCancelRequest $request, Purchase $purchase): JsonResponse
    {
        $this->ensurePurchaseVisible($purchase);

        return DB::transaction(function () use ($request, $purchase): JsonResponse {
            $reason = $request->validated()['reason'] ?? null;
            $this->purchaseCancellationService->cancel($purchase, $reason);

            return response()->json(['message' => 'Compra anulada y stock revertido.'], 200);
        });
    }

    public function deleteLine(Purchase $purchase, PurchaseLine $purchaseLine): JsonResponse
    {
        $this->ensurePurchaseVisible($purchase);
        if ((int) $purchaseLine->purchase_id !== (int) $purchase->id) {
            abort(404);
        }
        if ($purchase->status !== PurchaseStatus::Active) {
            return response()->json(['message' => 'Solo se pueden editar compras activas.'], 422);
        }

        $this->purchaseLineMutationService->deleteLine($purchase, $purchaseLine);

        return response()->json(['message' => 'Línea eliminada y stock revertido.'], 200);
    }

    public function updateLine(
        PurchaseLineUpdateRequest $request,
        Purchase $purchase,
        PurchaseLine $purchaseLine,
    ): JsonResponse {
        $this->ensurePurchaseVisible($purchase);
        if ((int) $purchaseLine->purchase_id !== (int) $purchase->id) {
            abort(404);
        }
        if ($purchase->status !== PurchaseStatus::Active) {
            return response()->json(['message' => 'Solo se pueden editar compras activas.'], 422);
        }

        $this->purchaseLineMutationService->updateLine(
            $purchase,
            $purchaseLine,
            $request->validated(),
        );

        return response()->json(['message' => 'Línea actualizada.'], 200);
    }

    protected function ensurePurchaseVisible(Purchase $purchase): void
    {
        if ($purchase->is_deleted) {
            abort(404);
        }
    }
}
