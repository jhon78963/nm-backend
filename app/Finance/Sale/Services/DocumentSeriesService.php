<?php

namespace App\Finance\Sale\Services;

use App\Finance\Sale\Models\DocumentSeries;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

class DocumentSeriesService
{
    /**
     * Genera el siguiente correlativo para un comprobante electrónico de forma
     * atómica usando Pessimistic Locking (SELECT … FOR UPDATE).
     *
     * Garantías:
     * - Nunca se emite el mismo correlativo dos veces, incluso bajo alta concurrencia.
     * - Si la serie no existe para ese almacén lanza ModelNotFoundException en lugar
     *   de crear un registro silencioso, forzando el alta explícita en el seeder/panel.
     *
     * @param  int    $warehouseId   ID del almacén emisor
     * @param  string $documentType  'BOLETA' | 'FACTURA'
     * @param  string $serie         Ej. 'B001', 'F001'
     * @return int                   El correlativo recién asignado (1-based)
     *
     * @throws ModelNotFoundException  Si la serie no está registrada
     * @throws \Throwable              Re-lanza cualquier fallo de BD
     */
    public function generateNextNumber(
        int $warehouseId,
        string $documentType,
        string $serie,
    ): int {
        return DB::transaction(function () use ($warehouseId, $documentType, $serie): int {

            /** @var DocumentSeries $record */
            $record = DocumentSeries::where('warehouse_id', $warehouseId)
                ->where('document_type', $documentType)
                ->where('serie', $serie)
                ->lockForUpdate()   // bloqueo exclusivo: bloquea otras transacciones concurrentes
                ->first();

            if ($record === null) {
                throw new ModelNotFoundException(
                    "Serie {$serie} ({$documentType}) no registrada para el almacén #{$warehouseId}."
                );
            }

            $record->current_number += 1;
            $record->save();

            return $record->current_number;
        });
    }

    /**
     * Formatea el número completo del comprobante con ceros a la izquierda.
     * Ej.: serie='B001', number=5  →  'B001-000005'
     */
    public function formatInvoiceNumber(string $serie, int $number): string
    {
        return sprintf('%s-%06d', $serie, $number);
    }

    /**
     * Atajo: genera el correlativo y devuelve el número completo formateado.
     */
    public function generateInvoiceNumber(
        int $warehouseId,
        string $documentType,
        string $serie,
    ): string {
        $number = $this->generateNextNumber($warehouseId, $documentType, $serie);

        return $this->formatInvoiceNumber($serie, $number);
    }
}
