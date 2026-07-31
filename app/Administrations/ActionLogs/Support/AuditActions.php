<?php

namespace App\Administrations\ActionLogs\Support;

/**
 * Catálogo de acciones auditables en user_action_logs.
 *
 * Todas las acciones son generadas por el middleware LogUserActivity —
 * los controladores no llaman a UserActionLogService directamente.
 * Excepción: auth.login / auth.login_failed (rutas públicas, fuera del middleware).
 */
final class AuditActions
{
    // ─── Autenticación ────────────────────────────────────────────────────────
    public const AUTH_LOGIN = 'auth.login';

    public const AUTH_LOGIN_FAILED = 'auth.login_failed';

    public const AUTH_LOGOUT = 'auth.logout';

    public const AUTH_PASSWORD_CHANGED = 'auth.password_changed';

    public const AUTH_PROFILE_UPDATED = 'auth.profile_updated';

    // ─── Roles ────────────────────────────────────────────────────────────────
    public const ROLE_LIST_VIEWED = 'role.list_viewed';

    public const ROLE_PERMISSIONS_INDEX_VIEWED = 'role.permissions_index_viewed';

    public const ROLE_VIEWED = 'role.viewed';

    public const ROLE_CREATED = 'role.created';

    public const ROLE_UPDATED = 'role.updated';

    public const ROLE_DELETED = 'role.deleted';

    public const ROLE_PERMISSIONS_SYNCED = 'role.permissions_synced';

    // ─── Usuarios ─────────────────────────────────────────────────────────────
    public const USER_LIST_VIEWED = 'user.list_viewed';

    public const USER_VIEWED = 'user.viewed';

    public const USER_CREATED = 'user.created';

    public const USER_UPDATED = 'user.updated';

    public const USER_DELETED = 'user.deleted';

    public const USER_PASSWORD_RESET = 'user.password_reset';

    // ─── Pagos de colaboradores ───────────────────────────────────────────────
    public const TEAM_PAYMENT_LIST_VIEWED = 'team_payment.list_viewed';

    public const TEAM_PAYMENT_PAYROLL_VIEWED = 'team_payment.payroll_viewed';

    public const TEAM_PAYMENT_CREATED = 'team_payment.created';

    public const TEAM_PAYMENT_UPDATED = 'team_payment.updated';

    public const TEAM_PAYMENT_DELETED = 'team_payment.deleted';

    // ─── POS ──────────────────────────────────────────────────────────────────
    public const POS_PRODUCT_SEARCHED = 'pos.product_searched';

    public const POS_CUSTOMER_SEARCHED = 'pos.customer_searched';

    public const POS_CHECKOUT = 'pos.checkout';

    // ─── Ventas ───────────────────────────────────────────────────────────────
    public const SALE_LIST_VIEWED = 'sale.list_viewed';

    public const SALE_STATS_VIEWED = 'sale.stats_viewed';

    public const SALE_VIEWED = 'sale.viewed';

    public const SALE_PDF_DOWNLOADED = 'sale.pdf_downloaded';

    public const SALE_UPDATED = 'sale.updated';

    public const SALE_DELETED = 'sale.deleted';

    public const SALE_EXCHANGED = 'sale.exchanged';

    // ─── Caja / movimientos ───────────────────────────────────────────────────
    public const CASHFLOW_DAILY_VIEWED = 'cashflow.daily_viewed';

    public const CASHFLOW_ADMIN_MONTHLY_VIEWED = 'cashflow.admin_monthly_viewed';

    public const CASHFLOW_ACCUMULATED_MONTHLY_VIEWED = 'cashflow.accumulated_monthly_viewed';

    public const CASHFLOW_CREATED = 'cashflow.created';

    public const CASHFLOW_UPDATED = 'cashflow.updated';

    public const CASHFLOW_DELETED = 'cashflow.deleted';
}
