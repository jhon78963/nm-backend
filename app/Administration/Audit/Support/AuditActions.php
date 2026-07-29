<?php

namespace App\Administration\Audit\Support;

/**
 * Acciones registradas en user_action_logs (mutaciones críticas).
 */
final class AuditActions
{
    public const ROLE_CREATED = 'role.created';

    public const ROLE_UPDATED = 'role.updated';

    public const ROLE_DELETED = 'role.deleted';

    public const ROLE_PERMISSIONS_SYNCED = 'role.permissions_synced';

    public const USER_CREATED = 'user.created';

    public const USER_UPDATED = 'user.updated';

    public const USER_DELETED = 'user.deleted';

    public const USER_PASSWORD_RESET = 'user.password_reset';

    public const TEAM_PAYMENT_CREATED = 'team_payment.created';

    public const TEAM_PAYMENT_UPDATED = 'team_payment.updated';

    public const TEAM_PAYMENT_DELETED = 'team_payment.deleted';

    public const SALE_DELETED = 'sale.deleted';

    public const SALE_UPDATED = 'sale.updated';

    public const SALE_VIEWED = 'sale.viewed';

    public const SALE_EXCHANGED = 'sale.exchanged';

    public const POS_CHECKOUT = 'pos.checkout';

    public const POS_PRODUCT_SEARCHED = 'pos.product_searched';

    public const POS_CUSTOMER_SEARCHED = 'pos.customer_searched';

    public const CASHFLOW_CREATED = 'cashflow.created';

    public const CASHFLOW_UPDATED = 'cashflow.updated';

    public const CASHFLOW_DELETED = 'cashflow.deleted';

    public const CASHFLOW_DAILY_VIEWED = 'cashflow.daily_viewed';
}
