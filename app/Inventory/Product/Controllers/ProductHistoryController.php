<?php

namespace App\Inventory\Product\Controllers;

use App\Finance\Sale\Models\Sale;
use App\Inventory\InventoryLedger\Enums\InventoryMovementDirection;
use App\Inventory\InventoryLedger\Enums\InventoryMovementType;
use App\Inventory\InventoryLedger\Models\InventoryBalance;
use App\Inventory\InventoryLedger\Models\InventoryMovement;
use App\Inventory\Product\Models\Product;
use App\Inventory\Product\Models\ProductHistory;
use App\Inventory\Purchase\Models\Purchase;
use App\Shared\Foundation\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ProductHistoryController extends Controller
{
    public function index(int $productId): JsonResponse
    {
        $auditLogs = ProductHistory::query()
            ->where('product_id', $productId)
            ->with('creator')
            ->orderBy('creation_time', 'desc')
            ->get();

        $auditLookup = $this->buildAuditLogLookupContext($auditLogs);

        $history = $auditLogs->map(function (ProductHistory $log) use ($auditLookup) {
            return [
                'id' => $log->id,
                'timestamp' => Carbon::parse($log->creation_time)->timestamp,
                'date' => Carbon::parse($log->creation_time)->format('d/m/Y'),
                'time' => Carbon::parse($log->creation_time)->format('h:i A'),
                'user' => $this->formatUserFullName($log->creator),
                'action_title' => $this->getActionTitle($log, $auditLookup),
                'changes' => $this->formatChanges($log->old_values, $log->new_values, $log->entity_type),
                'severity' => $this->getSeverity($log->event_type),
                'icon' => $this->getIcon($log->event_type, $log->entity_type),
            ];
        });

        $ledgerHistory = InventoryMovement::query()
            ->whereHas('productSize', static fn ($q) => $q->where('product_id', $productId))
            ->with([
                'productSize.size',
                'color',
                'createdBy',
                'reference' => static fn (MorphTo $morphTo) => $morphTo->morphWith([
                    Sale::class => [],
                    Purchase::class => [],
                    Product::class => [],
                    InventoryBalance::class => [],
                ]),
            ])
            ->orderBy('occurred_at', 'desc')
            ->get()
            ->filter(fn (InventoryMovement $movement) => $this->shouldShowMovementInProductHistory($movement))
            ->map(function (InventoryMovement $movement) {
                $date = Carbon::parse($movement->occurred_at);

                return [
                    'id' => 'movement-'.$movement->id,
                    'timestamp' => $date->timestamp,
                    'date' => $date->format('d/m/Y'),
                    'time' => $date->format('h:i A'),
                    'user' => $this->formatUserFullName($movement->createdBy),
                    'action_title' => $this->getMovementActionTitle($movement),
                    'changes' => $this->formatMovementChanges($movement),
                    'severity' => $this->getMovementSeverity($movement),
                    'icon' => $this->getMovementIcon($movement),
                ];
            });

        $history = $history
            ->concat($ledgerHistory)
            ->sortByDesc('timestamp')
            ->map(function (array $row): array {
                unset($row['timestamp']);

                return $row;
            })
            ->values();

        return response()->json([
            'success' => true,
            'data' => $history
        ]);
    }

    private function getActionTitle(ProductHistory $log, array $auditLookup): string
    {
        // CASOS ESPECIALES: VENTAS Y CAMBIOS
        if ($log->entity_type === 'SALE') {
            $code = $log->new_values['sale_code'] ?? '---';
            return "Venta Registrada ($code)";
        }
        if ($log->entity_type === 'EXCHANGE') {
            $code = $log->new_values['sale_code'] ?? '---';
            $type = $log->event_type === 'RETURNED' ? 'Devolución' : 'Salida por Cambio';

            $note = $log->new_values['exchange_note'] ?? $log->old_values['exchange_note'] ?? '';

            if ($note) {
                return "$type en Venta ($code) | $note";
            }

            return "$type en Venta ($code)";
        }

        // LÓGICA ESTÁNDAR (INVENTARIO)
        $extraInfo = '';
        if ($log->entity_type === 'SIZE') {
            $name = $auditLookup['sizes'][(int) $log->entity_id] ?? null;
            if ($name) {
                $extraInfo = " ($name)";
            }
        } elseif ($log->entity_type === 'COLOR') {
            $colorName = $log->old_values['color_name']
                ?? $log->new_values['color_name']
                ?? ($auditLookup['colors'][(int) $log->entity_id] ?? null);
            $extraInfo = $colorName ? " ($colorName)" : '';

            $sizeId = $log->new_values['size_id_ref'] ?? $log->old_values['size_id_ref'] ?? null;
            if (! $sizeId) {
                $psId = $log->new_values['product_size_id'] ?? $log->old_values['product_size_id'] ?? null;
                if ($psId) {
                    $sizeId = $auditLookup['product_size_to_size_id'][(int) $psId] ?? null;
                }
            }
            if ($sizeId) {
                $sizeName = $auditLookup['sizes'][(int) $sizeId] ?? null;
                if ($sizeName) {
                    $extraInfo .= " en Talla $sizeName";
                }
            }
        }

        $entity = match ($log->entity_type) {
            'PRODUCT' => 'Producto',
            'SIZE' => 'Talla' . $extraInfo,
            'COLOR' => 'Stock/Color' . $extraInfo,
            default => 'Item'
        };

        $action = match ($log->event_type) {
            'CREATED' => 'Creación',
            'UPDATED' => 'Actualización',
            'DELETED' => 'Eliminación',
            default => 'Movimiento'
        };

        return "$action de $entity";
    }

    private function getSeverity($eventType): string
    {
        return match ($eventType) {
            'CREATED' => 'success',
            'UPDATED' => 'info',
            'DELETED' => 'danger',
            'RETURNED' => 'warning',
            'TAKEN' => 'success',
            default => 'secondary'
        };
    }

    private function getIcon($eventType, $entityType): string
    {
        if ($entityType === 'SALE') return 'pi pi-shopping-cart';
        if ($entityType === 'EXCHANGE') return 'pi pi-sync';

        if ($eventType === 'DELETED') return 'pi pi-trash';
        if ($eventType === 'CREATED') return 'pi pi-plus';
        return 'pi pi-pencil';
    }

    private function formatChanges($old, $new, $entityType): array
    {
        $changes = [];

        // FORMATO ESPECIAL PARA VENTAS/CAMBIOS
        if ($entityType === 'SALE' || $entityType === 'EXCHANGE') {

            // 1. Mostrar cambio de STOCK (Lo que faltaba)
            if (isset($old['stock']) && isset($new['stock'])) {
                $changes[] = [
                    'field' => 'Stock',
                    'from' => $old['stock'],
                    'to' => $new['stock']
                ];
            }

            // 2. Mostrar Detalles de la Operación
            $qty = $new['quantity'] ?? 0;
            $price = $new['unit_price'] ?? 0;
            $size = $new['size_name'] ?? '-';
            $color = $new['color_name'] ?? '-';

            $changes[] = ['field' => 'Cantidad', 'from' => '-', 'to' => $qty];
            if ($price > 0) $changes[] = ['field' => 'Precio Unit.', 'from' => '-', 'to' => "S/ $price"];
            $changes[] = ['field' => 'Detalle', 'from' => '-', 'to' => "$size / $color"];

            return $changes;
        }

        // LÓGICA ESTÁNDAR (INVENTARIO)
        $old = $old ?? [];
        $new = $new ?? [];

        $labels = [
            'name' => 'Nombre',
            'stock' => 'Stock',
            'sale_price' => 'Precio Venta',
            'purchase_price' => 'Precio Compra',
            'status' => 'Estado',
            'barcode' => 'Código Barras',
        ];

        // Helper
        $resolveValue = function($key, $value) { return $value; };

        // Eliminación
        if (empty($new) && !empty($old)) {
            foreach ($old as $key => $value) {
                if ($this->shouldSkip($key)) continue;
                $changes[] = [
                    'field' => $labels[$key] ?? ucfirst($key),
                    'from' => $value,
                    'to' => 'ELIMINADO'
                ];
            }
            return $changes;
        }

        // Creación/Edición
        foreach ($new as $key => $value) {
            if ($this->shouldSkip($key)) continue;
            $oldValue = $old[$key] ?? '-';
            if ($oldValue != $value) {
                $changes[] = [
                    'field' => $labels[$key] ?? ucfirst($key),
                    'from' => $oldValue,
                    'to' => $value
                ];
            }
        }

        return $changes;
    }

    private function shouldSkip($key): bool
    {
        return in_array($key, [
            'id', 'product_id', 'size_id', 'size_id_ref',
            'color_id', 'color_name', 'product_size_id',
            'sale_code', 'exchange_note'
        ]);
    }

    /**
     * El kardex registra la venta en color y luego sincroniza el balance maestro
     * (color_id null) como Reconciliación sin referencia. Eso no es un evento de negocio:
     * en historial de producto solo mostramos la venta u otros movimientos explícitos.
     */
    private function shouldShowMovementInProductHistory(InventoryMovement $movement): bool
    {
        if ($movement->movement_type !== InventoryMovementType::Reconciliation) {
            return true;
        }

        return ! ($movement->color_id === null && $movement->reference_id === null);
    }

    private function getMovementActionTitle(InventoryMovement $movement): string
    {
        $reference = $this->resolveMovementReference($movement);
        $code = $reference !== null && isset($reference->code) ? (string) $reference->code : null;

        if ($movement->movement_type === InventoryMovementType::Sale) {
            return $code ? "Venta Registrada ($code)" : 'Venta Registrada';
        }

        $action = $movement->direction === InventoryMovementDirection::In ? 'Ingreso' : 'Salida';
        $type = match ($movement->movement_type) {
            InventoryMovementType::InitialInventory => 'Inventario Inicial',
            InventoryMovementType::Purchase => 'Compra / Orden',
            InventoryMovementType::PurchaseCancel => 'Anulación de Compra',
            InventoryMovementType::ManualAdjustment => 'Ajuste Manual',
            InventoryMovementType::Reconciliation => 'Reconciliación',
            InventoryMovementType::Transfer => 'Transferencia',
            default => 'Movimiento Kardex',
        };

        return "{$action} de {$type}";
    }

    private function formatMovementChanges(InventoryMovement $movement): array
    {
        $size = $movement->productSize?->size?->description ?? '-';
        $color = $movement->color?->description ?? 'Único';
        $quantity = (int) $movement->quantity;
        $balanceAfter = (int) $movement->balance_after_movement;
        $balanceBefore = $movement->direction === InventoryMovementDirection::In
            ? $balanceAfter - $quantity
            : $balanceAfter + $quantity;

        return [
            [
                'field' => 'Stock',
                'from' => $balanceBefore,
                'to' => $balanceAfter,
            ],
            [
                'field' => 'Cantidad',
                'from' => '-',
                'to' => $quantity,
            ],
            [
                'field' => 'Detalle',
                'from' => '-',
                'to' => "{$size} / {$color}",
            ],
        ];
    }

    private function getMovementSeverity(InventoryMovement $movement): string
    {
        return $movement->direction === InventoryMovementDirection::In ? 'success' : 'warning';
    }

    private function getMovementIcon(InventoryMovement $movement): string
    {
        if ($movement->movement_type === InventoryMovementType::Sale) {
            return 'pi pi-shopping-cart';
        }

        return $movement->direction === InventoryMovementDirection::In ? 'pi pi-plus' : 'pi pi-minus';
    }

    /**
     * @param  Collection<int, ProductHistory>  $auditLogs
     * @return array{
     *   sizes: array<int, string>,
     *   colors: array<int, string>,
     *   product_size_to_size_id: array<int, int>
     * }
     */
    private function buildAuditLogLookupContext(Collection $auditLogs): array
    {
        $sizeIds = [];
        $colorIds = [];
        $productSizeIds = [];

        foreach ($auditLogs as $log) {
            if ($log->entity_type === 'SIZE') {
                $sizeIds[(int) $log->entity_id] = true;
            }

            if ($log->entity_type !== 'COLOR') {
                continue;
            }

            $colorIds[(int) $log->entity_id] = true;

            $sizeIdRef = $log->new_values['size_id_ref'] ?? $log->old_values['size_id_ref'] ?? null;
            if ($sizeIdRef) {
                $sizeIds[(int) $sizeIdRef] = true;
            }

            $productSizeId = $log->new_values['product_size_id'] ?? $log->old_values['product_size_id'] ?? null;
            if ($productSizeId) {
                $productSizeIds[(int) $productSizeId] = true;
            }
        }

        $productSizeToSizeId = $productSizeIds === []
            ? []
            : DB::table('product_size')
                ->whereIn('id', array_keys($productSizeIds))
                ->pluck('size_id', 'id')
                ->map(static fn ($sizeId) => (int) $sizeId)
                ->all();

        foreach ($productSizeToSizeId as $sizeId) {
            if ($sizeId > 0) {
                $sizeIds[$sizeId] = true;
            }
        }

        $sizes = $sizeIds === []
            ? []
            : DB::table('sizes')
                ->whereIn('id', array_keys($sizeIds))
                ->pluck('description', 'id')
                ->map(static fn ($description) => (string) $description)
                ->all();

        $colors = $colorIds === []
            ? []
            : DB::table('colors')
                ->whereIn('id', array_keys($colorIds))
                ->pluck('description', 'id')
                ->map(static fn ($description) => (string) $description)
                ->all();

        return [
            'sizes' => $sizes,
            'colors' => $colors,
            'product_size_to_size_id' => $productSizeToSizeId,
        ];
    }

    private function resolveMovementReference(InventoryMovement $movement): ?Model
    {
        if ($movement->relationLoaded('reference')) {
            $reference = $movement->getRelation('reference');

            return $reference instanceof Model ? $reference : null;
        }

        return $movement->resolveReferenceModel();
    }

    private function formatUserFullName(?Model $user): string
    {
        if ($user === null) {
            return 'Sistema';
        }

        $surname = $user->paternal_surname ?? $user->surname ?? '';

        return trim($user->name.' '.$surname) ?: 'Sistema';
    }
}
