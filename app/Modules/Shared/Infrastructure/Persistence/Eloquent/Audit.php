<?php

declare(strict_types=1);

namespace App\Modules\Shared\Infrastructure\Persistence\Eloquent;

use App\Modules\Shared\Infrastructure\Traits\BelongsToTenant;
use OwenIt\Auditing\Models\Audit as OwenAuditModel;

final class Audit extends OwenAuditModel
{
    use BelongsToTenant;
}
