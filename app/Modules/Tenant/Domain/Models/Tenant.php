<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Domain\Models;

use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'domain', 'is_active'])]
class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory;

    protected static function newFactory(): TenantFactory
    {
        return TenantFactory::new();
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
