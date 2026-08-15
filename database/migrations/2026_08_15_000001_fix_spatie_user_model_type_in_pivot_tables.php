<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Corrige model_type legacy en pivots de Spatie Permission.
 *
 * Filas antiguas usaban App\Administrations\Users\Models\User (namespace incorrecto).
 * El modelo actual es App\Administration\User\Models\User.
 */
return new class extends Migration
{
    private const LEGACY_MODEL_TYPE = 'App\\Administrations\\Users\\Models\\User';

    private const CORRECT_MODEL_TYPE = 'App\\Administration\\User\\Models\\User';

    public function up(): void
    {
        foreach (['model_has_roles', 'model_has_permissions'] as $table) {
            if (! $this->tableExists($table)) {
                continue;
            }

            $this->fixPivotTable($table);
        }
    }

    public function down(): void
    {
        // No revertir: el namespace legacy era incorrecto.
    }

    private function tableExists(string $table): bool
    {
        return DB::getSchemaBuilder()->hasTable($table);
    }

    private function fixPivotTable(string $table): void
    {
        $legacyRows = DB::table($table)
            ->where('model_type', self::LEGACY_MODEL_TYPE)
            ->get();

        foreach ($legacyRows as $row) {
            $pivotKeyColumn = $this->pivotKeyColumn($table);

            $correctExists = DB::table($table)
                ->where($pivotKeyColumn, $row->{$pivotKeyColumn})
                ->where('model_id', $row->model_id)
                ->where('model_type', self::CORRECT_MODEL_TYPE)
                ->exists();

            $query = DB::table($table)
                ->where($pivotKeyColumn, $row->{$pivotKeyColumn})
                ->where('model_id', $row->model_id)
                ->where('model_type', self::LEGACY_MODEL_TYPE);

            if ($correctExists) {
                $query->delete();
            } else {
                $query->update(['model_type' => self::CORRECT_MODEL_TYPE]);
            }
        }
    }

    private function pivotKeyColumn(string $table): string
    {
        return $table === 'model_has_permissions' ? 'permission_id' : 'role_id';
    }
};
