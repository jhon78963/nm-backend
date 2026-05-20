<?php

namespace App\Inventory\Product\Controllers;

use App\Inventory\InventoryLedger\Enums\InventoryMovementDirection;
use App\Inventory\InventoryLedger\Enums\InventoryMovementType;
use App\Inventory\InventoryLedger\Models\InventoryMovement;
use App\Inventory\Product\Models\ProductHistory;
use App\Shared\Foundation\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class ProductHistoryController extends Controller
{
    public function index(int $productId): JsonResponse
    {
        $history = ProductHistory::where('product_id', $productId)
            ->orderBy('creation_time', 'desc')
            ->get()
            ->map(function ($log) {
                // Resolver usuario
                $user = DB::table('users')->where('id', $log->user_id)->first();
                $fullName = 'Sistema';
                if ($user) {
                    $surname = $user->paternal_surname ?? $user->surname ?? '';
                    $fullName = trim($user->name . ' ' . $surname);
                } elseif (!empty($log->user_name)) {
                    $fullName = $log->user_name;
                }

                return [
                    'id' => $log->id,
                    'timestamp' => Carbon::parse($log->creation_time)->timestamp,
                    'date' => Carbon::parse($log->creation_time)->format('d/m/Y'),
                    'time' => Carbon::parse($log->creation_time)->format('h:i A'),
                    'user' => $fullName,
                    'action_title' => $this->getActionTitle($log),
                    'changes' => $this->formatChanges($log->old_values, $log->new_values, $log->entity_type),
                    'severity' => $this->getSeverity($log->event_type),
                    'icon' => $this->getIcon($log->event_type, $log->entity_type)
                ];
            });

        $ledgerHistory = InventoryMovement::query()
            ->whereHas('productSize', static fn ($q) => $q->where('product_id', $productId))
            ->with(['productSize.size', 'color', 'createdBy'])
            ->orderBy('occurred_at', 'desc')
            ->get()
            ->map(function (InventoryMovement $movement) {
                $date = Carbon::parse($movement->occurred_at);
                $user = $movement->createdBy;
                $fullName = 'Sistema';
                if ($user) {
                    $surname = $user->paternal_surname ?? $user->surname ?? '';
                    $fullName = trim($user->name.' '.$surname);
                }

                return [
                    'id' => 'movement-'.$movement->id,
                    'timestamp' => $date->timestamp,
                    'date' => $date->format('d/m/Y'),
                    'time' => $date->format('h:i A'),
                    'user' => $fullName,
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

    private function getActionTitle($log): string
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
            $name = DB::table('sizes')->where('id', $log->entity_id)->value('description');
            if ($name) $extraInfo = " ($name)";
        } elseif ($log->entity_type === 'COLOR') {
            $colorName = $log->old_values['color_name']
                ?? $log->new_values['color_name']
                ?? DB::table('colors')->where('id', $log->entity_id)->value('description');
            $extraInfo = $colorName ? " ($colorName)" : '';

            $sizeId = $log->new_values['size_id_ref'] ?? $log->old_values['size_id_ref'] ?? null;
            if (!$sizeId) {
                $psId = $log->new_values['product_size_id'] ?? $log->old_values['product_size_id'] ?? null;
                if ($psId) $sizeId = DB::table('product_size')->where('id', $psId)->value('size_id');
            }
            if ($sizeId) {
                $sizeName = DB::table('sizes')->where('id', $sizeId)->value('description');
                if ($sizeName) $extraInfo .= " en Talla $sizeName";
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

    private function getMovementActionTitle(InventoryMovement $movement): string
    {
        $reference = $movement->resolveReferenceModel();
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
}
