<?php

declare(strict_types=1);

namespace App\Modules\Shared\Infrastructure\Persistence\Eloquent;

use App\Modules\Shared\Infrastructure\Traits\BelongsToTenant;
use Spatie\MediaLibrary\MediaCollections\Models\Media as SpatieMedia;

class Media extends SpatieMedia
{
    use BelongsToTenant;
}
