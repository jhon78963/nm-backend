<?php

namespace App\Administration\Tenant\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantSetting extends Model
{
    protected $table = 'tenant_settings';

    protected $fillable = [
        'tenant_id',
        'ruc',
        'legal_name',
        'trade_name',
        'address',
        'district',
        'province',
        'department',
        'phone',
        'email',
        'website',
        'social_links',
        'logo_url',
        'ticket_footer_note',
    ];

    protected $casts = [
        'social_links' => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** Devuelve la red social o null si no existe. */
    public function social(string $key): ?string
    {
        $links = $this->social_links ?? [];
        $value = $links[$key] ?? null;
        return (is_string($value) && $value !== '') ? $value : null;
    }
}
