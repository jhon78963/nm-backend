<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain;

final class TenantContext
{
    private static ?int $tenantId = null;

    public static function set(?int $tenantId): void
    {
        self::$tenantId = $tenantId;
    }

    public static function id(): ?int
    {
        return self::$tenantId;
    }

    public static function forget(): void
    {
        self::$tenantId = null;
    }
}
