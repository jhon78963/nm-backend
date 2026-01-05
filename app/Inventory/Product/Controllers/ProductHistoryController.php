<?php

namespace App\Inventory\Product\Controllers;

use App\Inventory\Product\Models\ProductHistory;
use App\Shared\Foundation\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Carbon\Carbon;
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
                    'date' => Carbon::parse($log->creation_time)->format('d/m/Y'),
                    'time' => Carbon::parse($log->creation_time)->format('h:i A'),
                    'user' => $fullName,
                    'action_title' => $this->getActionTitle($log),
                    'changes' => $this->formatChanges($log->old_values, $log->new_values, $log->entity_type),
                    'severity' => $this->getSeverity($log->event_type),
                    'icon' => $this->getIcon($log->event_type, $log->entity_type)
                ];
            });

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
}
