<?php

namespace App\Administration\Tenant\Models;

use App\Administration\Tenant\Enums\TenantFeature;
use App\Administration\User\Models\User;
use App\Inventory\Warehouse\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tenant extends Model
{
    protected $fillable = [
        'name',
        'is_active',
        'features',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'features'  => 'array',
        ];
    }

    // ── Relaciones ────────────────────────────────────────────────────────────

    public function warehouses(): HasMany
    {
        return $this->hasMany(Warehouse::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    // ── Feature Toggling ──────────────────────────────────────────────────────

    /**
     * Comprueba si el tenant tiene activo un módulo comercial.
     *
     * Acepta el enum o directamente el string del valor:
     *   $tenant->hasFeature(TenantFeature::Ecommerce)
     *   $tenant->hasFeature('ecommerce')
     */
    public function hasFeature(TenantFeature|string $feature): bool
    {
        $key = $feature instanceof TenantFeature ? $feature->value : $feature;

        return in_array($key, $this->features ?? [], true);
    }

    /**
     * Activa uno o varios módulos en el tenant.
     *
     *   $tenant->enableFeature(TenantFeature::ElectronicBilling);
     */
    public function enableFeature(TenantFeature|string ...$features): void
    {
        $keys    = array_map(
            fn ($f) => $f instanceof TenantFeature ? $f->value : $f,
            $features
        );
        $current = $this->features ?? [];
        $this->features = array_values(array_unique(array_merge($current, $keys)));
        $this->save();
    }

    /**
     * Desactiva uno o varios módulos del tenant.
     */
    public function disableFeature(TenantFeature|string ...$features): void
    {
        $keys = array_map(
            fn ($f) => $f instanceof TenantFeature ? $f->value : $f,
            $features
        );
        $this->features = array_values(
            array_filter($this->features ?? [], fn ($f) => ! in_array($f, $keys, true))
        );
        $this->save();
    }

    /**
     * Devuelve los módulos activos como instancias del enum (los no reconocidos se omiten).
     *
     * @return TenantFeature[]
     */
    public function activeFeatures(): array
    {
        return array_filter(
            array_map(fn ($v) => TenantFeature::tryFrom($v), $this->features ?? [])
        );
    }
}
