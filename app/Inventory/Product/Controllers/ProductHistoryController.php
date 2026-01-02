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
        $history = ProductHistory::with('creator:id,name,surname')
            ->where('product_id', $productId)
            ->orderByDesc('creation_time')
            ->get()
            ->map(function ($log) {

                $fullName = trim(
                    ($log->creator->name ?? '') . ' ' . ($log->creator->surname ?? '')
                );

                $dateTime = Carbon::parse($log->creation_time);

                return [
                    'id' => $log->id,
                    'date' => $dateTime->format('d/m/Y'),
                    'time' => $dateTime->format('h:i A'),
                    'user' => $fullName !== '' ? $fullName : 'Sistema',
                    'action_title' => $this->getActionTitle($log),
                    'changes' => $this->formatChanges($log->old_values, $log->new_values),
                    'severity' => $this->getSeverity($log->event_type),
                    'icon' => $this->getIcon($log->event_type, $log->entity_type),
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $history
        ]);
    }

    private function getActionTitle($log): string
    {
        // 1. Construimos el contexto (Nombre Talla / Color) para el título
        $extraInfo = '';

        if ($log->entity_type === 'SIZE') {
            // Buscamos el nombre de la talla
            $name = DB::table('sizes')->where('id', $log->entity_id)->value('description');
            if ($name)
                $extraInfo = " ($name)";
        } elseif ($log->entity_type === 'COLOR') {
            // A. Nombre del Color
            $colorName = $log->old_values['color_name']
                ?? $log->new_values['color_name']
                ?? DB::table('colors')->where('id', $log->entity_id)->value('description');

            $extraInfo = $colorName ? " ($colorName)" : '';

            // B. Nombre de la Talla Padre
            // Intento 1: Buscar referencia explícita guardada
            $sizeId = $log->new_values['size_id_ref'] ?? $log->old_values['size_id_ref'] ?? null;

            // Intento 2 (Fallback para Eliminaciones): Buscar a través del product_size_id
            if (!$sizeId) {
                $psId = $log->new_values['product_size_id'] ?? $log->old_values['product_size_id'] ?? null;
                if ($psId) {
                    $sizeId = DB::table('product_size')->where('id', $psId)->value('size_id');
                }
            }

            // Si encontramos el ID de la talla, buscamos su nombre
            if ($sizeId) {
                $sizeName = DB::table('sizes')->where('id', $sizeId)->value('description');
                if ($sizeName)
                    $extraInfo .= " en Talla $sizeName";
            }
        }

        $entity = match ($log->entity_type) {
            'PRODUCT' => 'Producto',
            'SIZE' => 'Talla' . $extraInfo,
            'COLOR' => 'Stock/Color' . $extraInfo, // Ej: Stock/Color (Rojo) en Talla M
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
            default => 'secondary'
        };
    }

    private function getIcon($eventType, $entityType): string
    {
        if ($eventType === 'DELETED')
            return 'pi pi-trash';
        if ($eventType === 'CREATED')
            return 'pi pi-plus';
        return 'pi pi-pencil';
    }

    private function formatChanges($old, $new): array
    {
        $changes = [];
        $old = $old ?? [];
        $new = $new ?? [];

        // Mapeo de nombres técnicos a amigables
        $labels = [
            'name' => 'Nombre',
            'stock' => 'Stock',
            'sale_price' => 'Precio Venta',
            'purchase_price' => 'Precio Compra',
            'status' => 'Estado',
            'barcode' => 'Código Barras',
            // Mapeos nuevos
            'warehouse_id' => 'Almacén',
            'gender_id' => 'Género',
            'product_size_id' => 'Talla',
            'size_id_ref' => 'Talla',
            'color_id' => 'Color',
            'color_name' => 'Color'
        ];

        // Helper para resolver valores
        $resolveValue = function ($key, $value) use ($old) {
            // Resolver Almacén
            if ($key === 'warehouse_id') {
                $name = DB::table('warehouses')->where('id', $value)->value('name');
                return $name ? "$name" : "#$value";
            }

            // Resolver Género
            if ($key === 'gender_id') {
                $name = DB::table('genders')->where('id', $value)->value('name');
                return $name ? "$name" : "#$value";
            }

            // Si es ID de Talla (Referencia directa a tabla sizes)
            if ($key === 'size_id_ref') {
                $name = DB::table('sizes')->where('id', $value)->value('description');
                return $name ? "$name" : "#$value";
            }

            // Si es ID de ProductSize (Tabla intermedia)
            if ($key === 'product_size_id') {
                $name = DB::table('product_size')
                    ->join('sizes', 'product_size.size_id', '=', 'sizes.id')
                    ->where('product_size.id', $value)
                    ->value('sizes.description');
                return $name ? "$name" : "#$value (Eliminado)";
            }

            // Si es ID de Color
            if ($key === 'color_id') {
                if (!empty($old['color_name']))
                    return null; // Preferimos usar el nombre guardado
                $name = DB::table('colors')->where('id', $value)->value('description');
                return $name ? "$name" : "#$value";
            }
            return $value;
        };

        // CASO 1: ELIMINACIÓN (New vacío)
        if (empty($new) && !empty($old)) {
            foreach ($old as $key => $value) {
                // Filtramos todo lo que ya está en el título
                if ($this->shouldSkip($key))
                    continue;

                $resolvedValue = $resolveValue($key, $value);
                if ($resolvedValue === null)
                    continue;

                $changes[] = [
                    'field' => $labels[$key] ?? ucfirst($key),
                    'from' => $resolvedValue,
                    'to' => 'ELIMINADO'
                ];
            }
            return $changes;
        }

        // CASO 2: CREACIÓN/EDICIÓN
        foreach ($new as $key => $value) {
            if ($this->shouldSkip($key))
                continue;

            $oldRaw = $old[$key] ?? '-';

            if ($oldRaw != $value) {
                $resolvedOld = $oldRaw !== '-' ? $resolveValue($key, $oldRaw) : '-';
                $resolvedNew = $resolveValue($key, $value);

                if ($resolvedNew === null)
                    continue;

                $changes[] = [
                    'field' => $labels[$key] ?? ucfirst($key),
                    'from' => $resolvedOld,
                    'to' => $resolvedNew
                ];
            }
        }

        return $changes;
    }

    private function shouldSkip($key): bool
    {
        // Ocultamos campos que ya se explican en el título para limpiar la vista
        return in_array($key, [
            'id',
            'product_id',
            'size_id',
            'size_id_ref',
            'color_id',
            'color_name',
            'product_size_id'
        ]);
    }
}
