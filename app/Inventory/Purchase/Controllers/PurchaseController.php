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
use App\Inventory\Purchase\Requests\PurchaseVoucherUpdateRequest;
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

        $purchaseId = DB::transaction(function () use ($request, $data): int {
            $id = $this->purchaseBulkService->handle($data);
            $this->recordPurchaseAccumulatedExpense($request, $data, $id);

            return $id;
        });

        return response()->json([
            'message' => 'Compra registrada e inventario actualizado.',
            'purchaseId' => $purchaseId,
        ], 201);
    }

    /**
     * Egreso automático en Cuenta Acumulada (ACCUMULATED).
     * No afecta caja operativa ni gastos administrativos del mes; evita doble gasto en P&L.
     */
    private function recordPurchaseAccumulatedExpense(
        PurchaseBulkRequest $request,
        array $data,
        int $purchaseId,
    ): void {
        $purchase = Purchase::query()->findOrFail($purchaseId);
        $totalAmount = (float) $purchase->total_subtotal;

        if ($totalAmount <= 0) {
            $totals = $data['totals'] ?? [];
            $totalAmount = (float) ($totals['grandSubtotal'] ?? 0);
        }

        if ($totalAmount <= 0) {
            return;
        }

        $supplierName = (string) ($purchase->supplier_name ?: ($data['purchase']['supplierName'] ?? 'Proveedor'));
        $registeredAt = $purchase->registered_at?->format('Y-m-d H:i:s')
            ?? (isset($data['purchase']['registeredAt'])
                ? Carbon::parse((string) $data['purchase']['registeredAt'])->format('Y-m-d H:i:s')
                : now()->format('Y-m-d H:i:s'));

        $this->cashflowService->registerAccumulatedExpense([
            'amount' => $totalAmount,
            'description' => "Compra de mercadería autogenerada - Ref: Compra #{$purchaseId} - Proveedor: {$supplierName}",
            'payment_method' => $request->input('payment_method', 'CASH'),
            'date' => $registeredAt,
            'purchase_id' => $purchaseId,
        ], $request->file('images') ?: null);
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
            'cashMovements.vouchers',
        ]);

        return response()->json(new PurchaseDetailResource($purchase));
    }

    public function addVouchers(PurchaseVoucherUpdateRequest $request, Purchase $purchase): JsonResponse
    {
        $this->ensurePurchaseVisible($purchase);

        if ($purchase->status !== PurchaseStatus::Active) {
            return response()->json(['message' => 'Solo se pueden agregar comprobantes a compras activas.'], 422);
        }

        $images = $request->file('images', []);
        if ($images === null || $images === []) {
            return response()->json(['message' => 'Selecciona al menos un comprobante.'], 422);
        }

        return DB::transaction(function () use ($purchase, $images): JsonResponse {
            $movement = $purchase->cashMovements()
                ->whereIn('category', [
                    CashMovement::CATEGORY_ACCUMULATED,
                    CashMovement::CATEGORY_INVENTORY_PURCHASE,
                ])
                ->with('vouchers')
                ->first();

            if ($movement === null) {
                $movement = $purchase->cashMovements()->with('vouchers')->first();
            }

            if ($movement === null) {
                $total = (float) $purchase->total_subtotal;

                if ($total <= 0) {
                    return response()->json(['message' => 'La compra no tiene un monto válido para vincular el pago.'], 422);
                }

                $registeredAt = $purchase->registered_at?->format('Y-m-d H:i:s') ?? now()->format('Y-m-d H:i:s');

                $this->cashflowService->registerAccumulatedExpense([
                    'amount' => $total,
                    'description' => "Compra de mercadería autogenerada - Ref: Compra #{$purchase->id} - Proveedor: {$purchase->supplier_name}",
                    'payment_method' => 'CASH',
                    'date' => $registeredAt,
                    'purchase_id' => $purchase->id,
                ], $images);
            } else {
                $currentCount = $movement->vouchers->count();
                if ($currentCount === 0 && $movement->voucher_path !== null && $movement->voucher_path !== '') {
                    $currentCount = 1;
                }

                if ($currentCount + count($images) > 10) {
                    return response()->json([
                        'message' => 'Máximo 10 comprobantes por compra. Ya tienes '.$currentCount.'.',
                    ], 422);
                }

                $this->cashflowService->updateMovement(
                    (int) $movement->id,
                    ['description' => $movement->description],
                    $images,
                );
            }

            return response()->json(['message' => 'Comprobantes agregados.'], 200);
        });
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
