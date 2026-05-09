<?php

namespace App\Administration\Tenant\Enums;

/**
 * Módulos comerciales disponibles para activar por tenant.
 *
 * Los valores (strings) son los que se almacenan en tenants.features (JSON array).
 * Úsalos en rutas protegidas:
 *
 *   Route::middleware('check.feature:electronic_billing')->group(fn () => ...);
 *   Route::middleware('check.feature:ecommerce,multi_branch')->group(fn () => ...);
 */
enum TenantFeature: string
{
    /** Emisión de comprobantes electrónicos (SUNAT, SAT, DIAN, etc.) */
    case ElectronicBilling = 'electronic_billing';

    /** Tienda en línea / catálogo público con carrito de compras */
    case Ecommerce = 'ecommerce';

    /** Gestión de múltiples sucursales / almacenes */
    case MultiBranch = 'multi_branch';

    /** Reportes avanzados y dashboards analíticos */
    case AdvancedReports = 'advanced_reports';

    /** Integración con pasarelas de pago externas */
    case PaymentGateway = 'payment_gateway';

    /** API pública para integraciones de terceros */
    case PublicApi = 'public_api';

    public function label(): string
    {
        return match($this) {
            self::ElectronicBilling => 'Facturación Electrónica',
            self::Ecommerce         => 'E-commerce',
            self::MultiBranch       => 'Multi-Sucursal',
            self::AdvancedReports   => 'Reportes Avanzados',
            self::PaymentGateway    => 'Pasarela de Pago',
            self::PublicApi         => 'API Pública',
        };
    }

    /** Devuelve todos los valores como array de strings (para seeds o UI). */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
