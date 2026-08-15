<?php

namespace App\Administration\Audit\Support;

use Illuminate\Http\Request;

/**
 * Resuelve el código de acción, descripción y metadata para cada petición HTTP.
 *
 * Las rutas conocidas se mapean a constantes de AuditActions.
 * Las rutas desconocidas caen en el formato genérico http.<method>.<path>.
 */
final class AuditHttpActionResolver
{
    /**
     * Combinaciones (method, path-prefix) que NO deben registrarse.
     * method '*' significa cualquier verbo HTTP.
     *
     * @return list<array{string, string}>
     */
    private static function excludedMethodPaths(): array
    {
        return [
            ['GET', 'api/auth/me'],            // Polling del perfil autenticado
            ['GET', 'api/profile'],            // Carga del perfil en /profile
            ['*',   'api/auth/csrf-token'],     // Token CSRF (llamada frecuente)
            ['*',   'api/user-action-logs'],    // Evitar meta-logging de los propios logs
            ['*',   'sanctum/csrf-cookie'],     // Cookie CSRF inicial
            ['*',   'api/pos/sales'],           // Rutas de impresión de ticket (HTML)
            ['*',   'api/cash-flow/vouchers'],  // Streaming de comprobantes (archivo)
        ];
    }

    public static function shouldLog(Request $request): bool
    {
        if (! in_array($request->method(), ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return false;
        }

        $path   = self::normalizePath($request->path());
        $method = strtoupper($request->method());

        foreach (self::excludedMethodPaths() as [$excMethod, $excPath]) {
            $methodOk = $excMethod === '*' || $excMethod === $method;
            $pathOk   = $path === $excPath || str_starts_with($path, $excPath.'/');
            if ($methodOk && $pathOk) {
                return false;
            }
        }

        return true;
    }

    public static function resolveAction(Request $request): string
    {
        $method = strtoupper($request->method());
        $path   = ltrim(preg_replace('#^api/#', '', self::normalizePath($request->path())), '/');

        return self::mapToKnownAction($method, $path)
            ?? self::buildGenericAction($method, $path);
    }

    public static function resolveDescription(Request $request): string
    {
        $action = self::resolveAction($request);

        return self::knownDescriptions()[$action]
            ?? strtoupper($request->method()).' /'.self::normalizePath($request->path());
    }

    /**
     * @return array<string, mixed>
     */
    public static function resolveMetadata(Request $request, int $statusCode): array
    {
        return [
            'method'      => $request->method(),
            'path'        => '/'.self::normalizePath($request->path()),
            'status_code' => $statusCode,
            'route'       => $request->route()?->getName(),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────

    private static function mapToKnownAction(string $method, string $path): ?string
    {
        // Auth (rutas privadas, sí pasan por el middleware)
        if ($method === 'PATCH' && $path === 'auth/me') {
            return AuditActions::AUTH_PROFILE_UPDATED;
        }
        if ($method === 'PUT' && $path === 'profile') {
            return AuditActions::AUTH_PROFILE_UPDATED;
        }
        if ($method === 'POST' && $path === 'profile/avatar') {
            return AuditActions::AUTH_PROFILE_UPDATED;
        }
        if ($method === 'PUT' && $path === 'profile/password') {
            return AuditActions::AUTH_PASSWORD_CHANGED;
        }
        if ($method === 'POST' && $path === 'auth/change-password') {
            return AuditActions::AUTH_PASSWORD_CHANGED;
        }
        if ($method === 'POST' && $path === 'auth/logout') {
            return AuditActions::AUTH_LOGOUT;
        }

        // POS
        if ($method === 'GET' && str_starts_with($path, 'pos/products')) {
            return AuditActions::POS_PRODUCT_SEARCHED;
        }
        if ($method === 'GET' && str_starts_with($path, 'pos/customers')) {
            return AuditActions::POS_CUSTOMER_SEARCHED;
        }
        if ($method === 'POST' && $path === 'pos/checkout') {
            return AuditActions::POS_CHECKOUT;
        }

        // Ventas — orden importa: rutas más específicas primero
        if ($method === 'GET' && $path === 'sales/monthly-stats') {
            return AuditActions::SALE_STATS_VIEWED;
        }
        if ($method === 'GET' && $path === 'sales') {
            return AuditActions::SALE_LIST_VIEWED;
        }
        if ($method === 'GET' && (bool) preg_match('#^sales/\d+/pdf$#', $path)) {
            return AuditActions::SALE_PDF_DOWNLOADED;
        }
        if ($method === 'GET' && (bool) preg_match('#^sales/\d+$#', $path)) {
            return AuditActions::SALE_VIEWED;
        }
        if ($method === 'PATCH' && (bool) preg_match('#^sales/\d+$#', $path)) {
            return AuditActions::SALE_UPDATED;
        }
        if ($method === 'DELETE' && (bool) preg_match('#^sales/\d+$#', $path)) {
            return AuditActions::SALE_DELETED;
        }
        if ($method === 'POST' && $path === 'sales/exchange') {
            return AuditActions::SALE_EXCHANGED;
        }

        // Caja / movimientos
        if ($method === 'GET' && $path === 'cash-flow/daily') {
            return AuditActions::CASHFLOW_DAILY_VIEWED;
        }
        if ($method === 'GET' && $path === 'cash-flow/admin/monthly') {
            return AuditActions::CASHFLOW_ADMIN_MONTHLY_VIEWED;
        }
        if ($method === 'GET' && $path === 'cash-flow/accumulated/monthly') {
            return AuditActions::CASHFLOW_ACCUMULATED_MONTHLY_VIEWED;
        }
        if ($method === 'POST' && $path === 'cash-flow') {
            return AuditActions::CASHFLOW_CREATED;
        }
        if ($method === 'PUT' && (bool) preg_match('#^cash-flow/\d+$#', $path)) {
            return AuditActions::CASHFLOW_UPDATED;
        }
        if ($method === 'DELETE' && (bool) preg_match('#^cash-flow/\d+$#', $path)) {
            return AuditActions::CASHFLOW_DELETED;
        }

        // Roles
        if ($method === 'GET' && $path === 'roles/permissions') {
            return AuditActions::ROLE_PERMISSIONS_INDEX_VIEWED;
        }
        if ($method === 'GET' && $path === 'roles') {
            return AuditActions::ROLE_LIST_VIEWED;
        }
        if ($method === 'GET' && (bool) preg_match('#^roles/\d+$#', $path)) {
            return AuditActions::ROLE_VIEWED;
        }
        if ($method === 'POST' && $path === 'roles') {
            return AuditActions::ROLE_CREATED;
        }
        if ($method === 'PATCH' && (bool) preg_match('#^roles/\d+$#', $path)) {
            return AuditActions::ROLE_UPDATED;
        }
        if ($method === 'DELETE' && (bool) preg_match('#^roles/\d+$#', $path)) {
            return AuditActions::ROLE_DELETED;
        }
        if ($method === 'POST' && (bool) preg_match('#^roles/\d+/sync-permissions$#', $path)) {
            return AuditActions::ROLE_PERMISSIONS_SYNCED;
        }

        // Usuarios
        if ($method === 'GET' && $path === 'users') {
            return AuditActions::USER_LIST_VIEWED;
        }
        if ($method === 'GET' && (bool) preg_match('#^users/\d+$#', $path)) {
            return AuditActions::USER_VIEWED;
        }
        if ($method === 'POST' && $path === 'users') {
            return AuditActions::USER_CREATED;
        }
        if ($method === 'PATCH' && (bool) preg_match('#^users/\d+/password$#', $path)) {
            return AuditActions::USER_PASSWORD_RESET;
        }
        if ($method === 'PATCH' && (bool) preg_match('#^users/\d+$#', $path)) {
            return AuditActions::USER_UPDATED;
        }
        if ($method === 'DELETE' && (bool) preg_match('#^users/\d+$#', $path)) {
            return AuditActions::USER_DELETED;
        }

        // Pagos de colaboradores
        if ($method === 'GET' && $path === 'payments/payroll') {
            return AuditActions::TEAM_PAYMENT_PAYROLL_VIEWED;
        }
        if ($method === 'GET' && str_starts_with($path, 'payments')) {
            return AuditActions::TEAM_PAYMENT_LIST_VIEWED;
        }
        if ($method === 'POST' && $path === 'payments') {
            return AuditActions::TEAM_PAYMENT_CREATED;
        }
        if ($method === 'PATCH' && (bool) preg_match('#^payments/\d+$#', $path)) {
            return AuditActions::TEAM_PAYMENT_UPDATED;
        }
        if ($method === 'DELETE' && (bool) preg_match('#^payments/\d+$#', $path)) {
            return AuditActions::TEAM_PAYMENT_DELETED;
        }

        return null;
    }

    private static function buildGenericAction(string $method, string $path): string
    {
        $normalized = (string) preg_replace('/[^a-z0-9.]+/', '_', str_replace('/', '.', strtolower($path)));

        return 'http.'.strtolower($method).'.'.$normalized;
    }

    /**
     * @return array<string, string>
     */
    private static function knownDescriptions(): array
    {
        return [
            AuditActions::AUTH_LOGOUT                        => 'Cierre de sesión',
            AuditActions::AUTH_PASSWORD_CHANGED              => 'Contraseña actualizada',
            AuditActions::AUTH_PROFILE_UPDATED               => 'Perfil actualizado',
            AuditActions::POS_PRODUCT_SEARCHED               => 'Consulta de producto en POS',
            AuditActions::POS_CUSTOMER_SEARCHED              => 'Consulta de cliente en POS',
            AuditActions::POS_CHECKOUT                       => 'Venta POS procesada',
            AuditActions::SALE_LIST_VIEWED                   => 'Lista de ventas consultada',
            AuditActions::SALE_STATS_VIEWED                  => 'Estadísticas de ventas consultadas',
            AuditActions::SALE_VIEWED                        => 'Detalle de venta consultado',
            AuditActions::SALE_PDF_DOWNLOADED                => 'Comprobante de venta descargado',
            AuditActions::SALE_UPDATED                       => 'Venta actualizada',
            AuditActions::SALE_DELETED                       => 'Venta eliminada',
            AuditActions::SALE_EXCHANGED                     => 'Cambio de mercadería procesado',
            AuditActions::CASHFLOW_DAILY_VIEWED              => 'Caja del día consultada',
            AuditActions::CASHFLOW_ADMIN_MONTHLY_VIEWED      => 'Reporte mensual de caja consultado',
            AuditActions::CASHFLOW_ACCUMULATED_MONTHLY_VIEWED => 'Reporte acumulado mensual consultado',
            AuditActions::CASHFLOW_CREATED                   => 'Movimiento de caja registrado',
            AuditActions::CASHFLOW_UPDATED                   => 'Movimiento de caja actualizado',
            AuditActions::CASHFLOW_DELETED                   => 'Movimiento de caja eliminado',
            AuditActions::ROLE_LIST_VIEWED                   => 'Lista de roles consultada',
            AuditActions::ROLE_PERMISSIONS_INDEX_VIEWED      => 'Lista de permisos consultada',
            AuditActions::ROLE_VIEWED                        => 'Detalle de rol consultado',
            AuditActions::ROLE_CREATED                       => 'Rol creado',
            AuditActions::ROLE_UPDATED                       => 'Rol actualizado',
            AuditActions::ROLE_DELETED                       => 'Rol eliminado',
            AuditActions::ROLE_PERMISSIONS_SYNCED            => 'Permisos de rol sincronizados',
            AuditActions::USER_LIST_VIEWED                   => 'Lista de usuarios consultada',
            AuditActions::USER_VIEWED                        => 'Detalle de usuario consultado',
            AuditActions::USER_CREATED                       => 'Usuario creado',
            AuditActions::USER_UPDATED                       => 'Usuario actualizado',
            AuditActions::USER_DELETED                       => 'Usuario deshabilitado',
            AuditActions::USER_PASSWORD_RESET                => 'Contraseña de usuario restablecida',
            AuditActions::TEAM_PAYMENT_LIST_VIEWED           => 'Lista de pagos de colaboradores consultada',
            AuditActions::TEAM_PAYMENT_PAYROLL_VIEWED        => 'Nómina de colaboradores consultada',
            AuditActions::TEAM_PAYMENT_CREATED               => 'Pago de colaborador registrado',
            AuditActions::TEAM_PAYMENT_UPDATED               => 'Pago de colaborador actualizado',
            AuditActions::TEAM_PAYMENT_DELETED               => 'Pago de colaborador eliminado',
        ];
    }

    private static function normalizePath(string $path): string
    {
        return trim($path, '/');
    }
}
