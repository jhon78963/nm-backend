<?php

namespace App\Administration\Role\Support;

/**
 * Etiquetas en español para permisos técnicos (módulo.acción).
 */
final class PermissionLabels
{
    /** @var array<string, string> */
    private const MODULE = [
        'sale' => 'Ventas',
        'pos' => 'Punto de venta',
        'purchase' => 'Compras',
        'color' => 'Colores',
        'cashflow' => 'Flujo de caja',
        'order' => 'Pedidos',
        'warehouse' => 'Tiendas',
        'tenant' => 'Clientes (SaaS)',
        'team' => 'Colaboradores',
        'attendance' => 'Asistencias',
        'payment' => 'Pagos del equipo',
        'product' => 'Productos',
        'productSize' => 'Tallas del producto',
        'productSizeColor' => 'Colores por talla',
        'productImage' => 'Imágenes del producto',
        'productHistory' => 'Historial de producto',
        'expense' => 'Gastos',
        'report' => 'Reportes',
        'financialSummary' => 'Resumen financiero',
        'size' => 'Tallas',
        'vendor' => 'Proveedores',
        'customer' => 'Clientes',
        'role' => 'Roles',
        'user' => 'Usuarios',
        'image' => 'Imágenes',
        'gender' => 'Géneros',
    ];

    /** @var array<string, string> */
    private const ACTION = [
        'getMonthlyStats' => 'Estadísticas mensuales',
        'getByMonth' => 'Consultar por mes',
        'getDailySummary' => 'Resumen diario (todo el equipo)',
        'getAdminMonthlyReport' => 'Reporte mensual (administración)',
        'getDaily' => 'Consultar movimiento diario',
        'getAll' => 'Listar',
        'getAllSelected' => 'Listar seleccionados',
        'getAllSelectedAttached' => 'Listar seleccionados adjuntos',
        'getAllAutocomplete' => 'Autocompletar en listado',
        'getAutocomplete' => 'Autocompletar',
        'getSizes' => 'Ver tallas',
        'getSizeType' => 'Ver tipo de talla',
        'syncPermissions' => 'Sincronizar permisos',
        'permissionsIndex' => 'Listar permisos disponibles',
        'registerBulk' => 'Registrar compra masiva',
        'updateLine' => 'Actualizar línea',
        'deleteLine' => 'Eliminar línea',
        'searchProduct' => 'Buscar producto',
        'searchCustomer' => 'Buscar cliente',
        'checkout' => 'Cobrar en caja',
        'multipleAdd' => 'Subir varias imágenes',
        'multipleRemove' => 'Eliminar varias imágenes',
        'getSummary' => 'Ver resumen',
        'index' => 'Ver historial / índice',
        'create' => 'Crear',
        'update' => 'Editar',
        'delete' => 'Eliminar',
        'get' => 'Ver detalle',
        'store' => 'Registrar',
        'cancel' => 'Anular',
        'exchange' => 'Registrar cambio / devolución',
        'add' => 'Añadir',
        'modify' => 'Modificar',
        'remove' => 'Quitar',
    ];

    public static function group(string $name): string
    {
        $moduleKey = explode('.', $name, 2)[0];

        return self::MODULE[$moduleKey] ?? self::humanizeSegment($moduleKey);
    }

    public static function label(string $name): string
    {
        $parts = explode('.', $name, 2);
        $module = self::MODULE[$parts[0]] ?? self::humanizeSegment($parts[0]);
        if (! isset($parts[1])) {
            return $module;
        }
        $action = self::ACTION[$parts[1]] ?? self::humanizeSegment($parts[1]);

        return $module.' · '.$action;
    }

    private static function humanizeSegment(string $segment): string
    {
        $s = preg_replace('/([a-z])([A-Z])/', '$1 $2', $segment);
        $s = str_replace(['_', '.'], [' ', ' '], (string) $s);

        return mb_convert_case(mb_strtolower($s, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
    }
}
