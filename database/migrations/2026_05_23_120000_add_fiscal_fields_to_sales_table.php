<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            // Tipo de comprobante fiscal
            $table->enum('document_type', ['BOLETA', 'FACTURA', 'TICKET_INTERNO'])
                ->nullable()
                ->after('code');

            // Numeración SUNAT: serie + correlativo + número completo desnormalizado
            $table->string('serie', 4)->nullable()->after('document_type');   // B001 / F001
            $table->unsignedInteger('correlativo')->nullable()->after('serie');
            $table->string('full_invoice_number', 20)
                ->nullable()
                ->unique()
                ->after('correlativo');                                        // B001-000123

            // Montos fiscales separados del total existente
            $table->decimal('taxable_base', 12, 2)->nullable()->after('full_invoice_number');
            $table->decimal('igv_amount', 12, 2)->nullable()->after('taxable_base');

            // Ciclo de vida del documento electrónico
            $table->enum('sunat_status', ['PENDING', 'SENT', 'ACCEPTED', 'REJECTED', 'VOIDED'])
                ->nullable()
                ->after('igv_amount');

            // Rutas a los archivos generados (storage)
            $table->string('xml_path')->nullable()->after('sunat_status');
            $table->string('cdr_path')->nullable()->after('xml_path');

            // Índice parcial para búsquedas por estado de envío
            $table->index(['sunat_status'], 'idx_sales_sunat_status');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropIndex('idx_sales_sunat_status');
            $table->dropUnique(['full_invoice_number']);
            $table->dropColumn([
                'document_type',
                'serie',
                'correlativo',
                'full_invoice_number',
                'taxable_base',
                'igv_amount',
                'sunat_status',
                'xml_path',
                'cdr_path',
            ]);
        });
    }
};
