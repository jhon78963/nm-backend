<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Models;

use App\Modules\Administration\Domain\Models\Store;
use App\Modules\Shared\Domain\Contracts\SkipsStrictTenantScopeWhenContextMissing;
use App\Modules\Shared\Infrastructure\Traits\BelongsToTenant;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media as SpatieMedia;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password', 'tenant_id', 'store_id'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements AuditableContract, HasMedia, SkipsStrictTenantScopeWhenContextMissing
{
    /** @use HasFactory<UserFactory> */
    use AuditableTrait, BelongsToTenant, HasApiTokens, HasFactory, HasRoles, InteractsWithMedia, Notifiable;

    /**
     * @var list<string>
     */
    protected array $auditExclude = [
        'password',
        'remember_token',
    ];

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'tenant_id' => 'integer',
            'store_id' => 'integer',
        ];
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('profile')
            ->singleFile()
            ->acceptsMimeTypes([
                'image/jpeg',
                'image/png',
                'image/gif',
                'image/webp',
            ]);
    }

    public function registerMediaConversions(?SpatieMedia $media = null): void
    {
        //
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
