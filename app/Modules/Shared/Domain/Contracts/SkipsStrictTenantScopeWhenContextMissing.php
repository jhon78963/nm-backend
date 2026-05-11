<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Contracts;

/**
 * Models that implement this contract are not restricted when no tenant is active in context.
 * Use for identity resolution (e.g. login) before {@see \App\Modules\Shared\Domain\TenantContext} is set.
 */
interface SkipsStrictTenantScopeWhenContextMissing
{
}
