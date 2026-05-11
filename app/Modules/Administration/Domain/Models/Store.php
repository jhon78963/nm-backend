<?php

declare(strict_types=1);

namespace App\Modules\Administration\Domain\Models;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Shared\Infrastructure\Traits\BelongsToTenant;
use App\Modules\Tenant\Domain\Models\Tenant;
use Database\Factories\StoreFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media as SpatieMedia;

#[Fillable(['tenant_id', 'name', 'address', 'is_active'])]
class Store extends Model implements AuditableContract, HasMedia
{
    /** @use HasFactory<StoreFactory> */
    use AuditableTrait, BelongsToTenant, HasFactory, InteractsWithMedia;

    protected static function newFactory(): StoreFactory
    {
        return StoreFactory::new();
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function registerMediaCollections(): void
    {
        //
    }

    public function registerMediaConversions(?SpatieMedia $media = null): void
    {
        //
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'store_id');
    }
}
