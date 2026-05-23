<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_series', function (Blueprint $table) {
            $table->id();

            $table->foreignId('warehouse_id')
                ->constrained('warehouses')
                ->cascadeOnDelete();

            // Solo comprobantes con numeración SUNAT
            $table->enum('document_type', ['BOLETA', 'FACTURA']);

            // Serie asignada por SUNAT (B001, F001, BT01…)
            $table->string('serie', 4);

            // Último correlativo emitido; se incrementa con lockForUpdate
            $table->unsignedInteger('current_number')->default(0);

            $table->timestamps();

            // Un almacén no puede tener dos filas para el mismo tipo+serie
            $table->unique(['warehouse_id', 'document_type', 'serie'], 'uq_document_series');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_series');
    }
};
