<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')
                  ->unique()
                  ->constrained('tenants')
                  ->cascadeOnDelete();

            // ── Identidad fiscal ─────────────────────────────────────────────
            $table->string('ruc', 11)->nullable();
            $table->string('legal_name')->nullable();        // razón social
            $table->string('trade_name')->nullable();        // nombre comercial

            // ── Dirección fiscal ─────────────────────────────────────────────
            $table->string('address')->nullable();
            $table->string('district')->nullable();
            $table->string('province')->nullable();
            $table->string('department')->nullable();

            // ── Contacto ──────────────────────────────────────────────────────
            $table->string('phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->string('website')->nullable();

            // ── Redes sociales (JSONB — flexible para agregar más canales) ───
            // Estructura: { "facebook": "...", "instagram": "...", "tiktok": "..." }
            $table->jsonb('social_links')->nullable();

            // ── Branding del ticket ──────────────────────────────────────────
            $table->string('logo_url')->nullable();           // URL pública o ruta storage
            $table->string('ticket_footer_note')->nullable(); // ej. "No se aceptan devoluciones"

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_settings');
    }
};
