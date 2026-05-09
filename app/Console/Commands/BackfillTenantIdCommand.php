<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PASO INTERMEDIO — Backfill de tenant_id en todas las tablas.
 *
 * Estrategia por tipo de tabla:
 *  - warehouses          → asigna el tenant por defecto a los huérfanos
 *  - users               → deriva de warehouse_id; fallback al tenant por defecto
 *  - Dominio transaccional → deriva de warehouse_id; fallback al tenant por defecto
 *  - Catálogos           → asigna el tenant por defecto (son recursos globales migrados)
 *  - Spatie roles        → asigna el tenant por defecto
 *  - Spatie model_has_roles → deriva de roles.tenant_id; fallback al tenant por defecto
 *
 * Uso:
 *   php artisan tenant:backfill
 *   php artisan tenant:backfill --tenant-id=2
 */
class BackfillTenantIdCommand extends Command
{
    protected $signature = 'tenant:backfill
                            {--tenant-id=1 : ID del tenant por defecto para registros huérfanos}';

    protected $description = 'Asigna tenant_id a todos los registros huérfanos en tablas transaccionales, maestras y de Spatie';

    private int $defaultTenantId;

    public function handle(): int
    {
        $this->defaultTenantId = (int) $this->option('tenant-id');

        if (! DB::table('tenants')->where('id', $this->defaultTenantId)->exists()) {
            $this->error("No existe un tenant con ID {$this->defaultTenantId}.");
            $this->newLine();
            $this->line('Tenants disponibles:');
            DB::table('tenants')->orderBy('id')->each(function (object $t) {
                $this->line("  [{$t->id}] {$t->name}");
            });

            return self::FAILURE;
        }

        $this->info("Iniciando backfill con tenant_id = {$this->defaultTenantId}...");
        $this->newLine();

        // ── 1. Infraestructura ───────────────────────────────────────────────
        $this->comment('[ Infraestructura ]');
        $this->backfillSimple('warehouses');
        $this->backfillUsers();

        // ── 2. Dominio transaccional (deriva tenant desde warehouse) ─────────
        $this->newLine();
        $this->comment('[ Tablas de dominio ]');

        $domainTables = [
            'customers',
            'vendors',
            'sales',
            'cash_movements',
            'expenses',
            'orders',
            'images',
            'collections',
            'products',
            'teams',
            'purchases',
        ];

        foreach ($domainTables as $tableName) {
            $this->backfillByWarehouse($tableName);
        }

        // ── 3. Catálogos globales ────────────────────────────────────────────
        $this->newLine();
        $this->comment('[ Catálogos ]');

        foreach (['size_types', 'sizes', 'colors', 'genders', 'payment_methods'] as $tableName) {
            $this->backfillSimple($tableName);
        }

        // ── 4. Spatie ────────────────────────────────────────────────────────
        $this->newLine();
        $this->comment('[ Spatie ]');
        $this->backfillSpatieRoles();
        $this->backfillSpatieModelHasRoles();

        $this->newLine();
        $this->info('Backfill completado exitosamente.');

        return self::SUCCESS;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Asigna directamente el tenant por defecto a todos los registros
     * que no tienen tenant_id en la tabla indicada.
     */
    private function backfillSimple(string $tableName): void
    {
        if (! $this->columnExists($tableName, 'tenant_id')) {
            return;
        }

        $count = DB::table($tableName)
            ->whereNull('tenant_id')
            ->update(['tenant_id' => $this->defaultTenantId]);

        $this->printResult($tableName, $count);
    }

    /**
     * Para tablas de dominio:
     *  1. Deriva tenant_id desde warehouses.tenant_id vía un JOIN UPDATE.
     *  2. Para los que no tienen warehouse_id (o el warehouse no tiene tenant),
     *     aplica el fallback al tenant por defecto.
     */
    private function backfillByWarehouse(string $tableName): void
    {
        if (! $this->columnExists($tableName, 'tenant_id')) {
            return;
        }

        $byWarehouse = 0;

        if (Schema::hasColumn($tableName, 'warehouse_id')) {
            // Sintaxis PostgreSQL: UPDATE target SET col = origin.col FROM origin WHERE target.fk = origin.id
            $byWarehouse = DB::affectingStatement("
                UPDATE \"{$tableName}\"
                SET tenant_id = w.tenant_id
                FROM warehouses w
                WHERE \"{$tableName}\".warehouse_id = w.id
                  AND \"{$tableName}\".tenant_id IS NULL
                  AND \"{$tableName}\".warehouse_id IS NOT NULL
                  AND w.tenant_id IS NOT NULL
            ");
        }

        // Fallback para registros sin warehouse_id o cuyo warehouse tampoco tiene tenant
        $fallback = DB::table($tableName)
            ->whereNull('tenant_id')
            ->update(['tenant_id' => $this->defaultTenantId]);

        $this->printResult($tableName, $byWarehouse + $fallback, $byWarehouse);
    }

    /**
     * users: igual que backfillByWarehouse pero con lógica especial ya
     * que también puede venir de tenant directamente.
     */
    private function backfillUsers(): void
    {
        if (! $this->columnExists('users', 'tenant_id')) {
            return;
        }

        $byWarehouse = 0;

        if (Schema::hasColumn('users', 'warehouse_id')) {
            $byWarehouse = DB::affectingStatement('
                UPDATE "users"
                SET tenant_id = w.tenant_id
                FROM "warehouses" w
                WHERE "users".warehouse_id = w.id
                  AND "users".tenant_id IS NULL
                  AND "users".warehouse_id IS NOT NULL
                  AND w.tenant_id IS NOT NULL
            ');
        }

        $fallback = DB::table('users')
            ->whereNull('tenant_id')
            ->update(['tenant_id' => $this->defaultTenantId]);

        $this->printResult('users', $byWarehouse + $fallback, $byWarehouse);
    }

    private function backfillSpatieRoles(): void
    {
        if (! $this->columnExists('roles', 'tenant_id')) {
            return;
        }

        $count = DB::table('roles')
            ->whereNull('tenant_id')
            ->update(['tenant_id' => $this->defaultTenantId]);

        $this->printResult('roles (Spatie)', $count);
    }

    private function backfillSpatieModelHasRoles(): void
    {
        if (! $this->columnExists('model_has_roles', 'tenant_id')) {
            return;
        }

        // Primero: deriva tenant_id desde el rol asignado (roles.tenant_id)
        $byRole = DB::affectingStatement('
            UPDATE "model_has_roles"
            SET tenant_id = r.tenant_id
            FROM "roles" r
            WHERE "model_has_roles".role_id = r.id
              AND "model_has_roles".tenant_id IS NULL
              AND r.tenant_id IS NOT NULL
        ');

        // Fallback para los que aún queden sin tenant
        $fallback = DB::table('model_has_roles')
            ->whereNull('tenant_id')
            ->update(['tenant_id' => $this->defaultTenantId]);

        $this->printResult('model_has_roles (Spatie)', $byRole + $fallback, $byRole);
    }

    private function columnExists(string $table, string $column): bool
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            $this->warn("  {$table}.{$column}: columna no existe, omitiendo.");

            return false;
        }

        return true;
    }

    private function printResult(string $label, int $total, ?int $byDerivation = null): void
    {
        if ($byDerivation !== null) {
            $this->line(
                "  <fg=green>{$label}</>: {$total} actualizados "
                . "(<fg=cyan>{$byDerivation}</> derivados de warehouse, "
                . '<fg=yellow>' . ($total - $byDerivation) . '</> por fallback)'
            );
        } else {
            $this->line("  <fg=green>{$label}</>: {$total} registros actualizados");
        }
    }
}
