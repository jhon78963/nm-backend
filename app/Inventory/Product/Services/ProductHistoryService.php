<?php

namespace App\Inventory\Product\Services;

use App\Inventory\Product\Models\ProductHistory;
use Illuminate\Support\Facades\Auth;

class ProductHistoryService
{
    /**
     * Registra un cambio en el historial.
     */
    public function logChange($product, string $entityType, int $entityId, string $eventType, $oldData = null, $newData = null): void
    {
        // Si es un UPDATE y no hay cambios reales, no guardamos nada
        if ($eventType === 'UPDATED' && $oldData == $newData) {
            return;
        }

        // Limpiamos campos de auditoría internos para que no ensucien el historial visual
        $ignoreFields = ['last_modification_time', 'last_modifier_user_id', 'updated_at'];

        if (is_array($oldData)) {
            $oldData = array_diff_key($oldData, array_flip($ignoreFields));
        }
        if (is_array($newData)) {
            $newData = array_diff_key($newData, array_flip($ignoreFields));
        }

        ProductHistory::create([
            'product_id'  => $product->id, // Siempre vinculamos al ID del producto padre
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
            'event_type'  => $eventType,
            'old_values'  => $oldData,
            'new_values'  => $newData,
            'creator_user_id'     => Auth::id(),
        ]);
    }
}
