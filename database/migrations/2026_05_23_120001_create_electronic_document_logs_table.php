<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('electronic_document_logs', function (Blueprint $table) {
            $table->id();

            // Convención de auditoría del proyecto
            $table->datetime('creation_time')->default(DB::raw('CURRENT_TIMESTAMP'));

            // Vínculo con la venta
            $table->foreignId('sale_id')
                ->constrained('sales')
                ->cascadeOnDelete();

            // Acción ejecutada contra SUNAT/OSE
            $table->enum('action', ['ISSUE', 'VOID', 'CHECK_STATUS']);

            // Payload enviado y respuesta recibida
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();

            // Código de respuesta SUNAT (ej. 0, 1033, 2800…)
            $table->string('sunat_code', 10)->nullable();

            $table->timestamps();

            // Consultas frecuentes: logs de una venta, logs por acción
            $table->index(['sale_id', 'action'], 'idx_edl_sale_action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('electronic_document_logs');
    }
};
