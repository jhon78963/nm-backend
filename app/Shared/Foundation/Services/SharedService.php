<?php
namespace App\Shared\Foundation\Services;

use App\Shared\Foundation\Requests\GetAllRequest;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Carbon\Carbon;
use Str;

class SharedService
{
    private int $limit = 10;
    private int $page = 1;
    private string $search = '';
    // ... resto de propiedades ...

    public function convertCamelToSnake(array $array): array
    {
        $result = [];

        foreach ($array as $key => $value) {
            $snakeKey = Str::snake($key);

            if (is_array($value)) {
                $result[$snakeKey] = $this->convertCamelToSnake($value);
            } else {
                $result[$snakeKey] = $value;
            }
        }

        return $result;
    }

    public function dateFormat($date)
    {
        if ($date === null) {
            return null;
        }
        $date = Carbon::createFromFormat('Y-m-d h:i:s', $date);
        $date = $date->format('d/m/Y h:i:s A');
        return $date;
    }

    public function query(
        GetAllRequest $request,
        string $entityName,
        string $modelName,
        array|string $columnSearch = null,
        array $filters = [],
        callable|null $extendQuery = null,
        string $orderBy = 'id',
        string $orderDir = 'asc',
        callable|null $searchFilter = null,
    ): array {
        $limit = $request->query('limit', $this->limit);
        $page = $request->query('page', $this->page);
        $search = $request->query('search', $this->search);

        $modelClass = "App\\$entityName\\Models\\$modelName";

        $query = $modelClass::query();

        if ($extendQuery) {
            $extendQuery($query);
        }

        $query->where('is_deleted', false);

        // ... lógica de filtros existente ...
        if (!empty($filters)) {
            foreach ($filters as $column => $value) {
                if (str_contains((string) $value, ',')) {
                    $values = explode(',', $value);
                    $nonNullValues = [];
                    $includesNull = false;
                    foreach ($values as $v) {
                        if (strtolower($v) === 'null') {
                            $includesNull = true;
                        } elseif (!empty($v)) {
                            $nonNullValues[] = $v;
                        }
                    }

                    $query->where(function ($q) use ($column, $nonNullValues, $includesNull) {
                        if (!empty($nonNullValues)) {
                            $q->whereIn($column, $nonNullValues);
                        }
                        if ($includesNull) {
                            $q->orWhereNull($column);
                        }
                    });

                } else {
                    if (strtolower((string) $value) === 'null') {
                        $query->whereNull($column);
                    } else {
                        $query->where($column, $value);
                    }
                }
            }
        }

        if ($search) {
            if ($searchFilter !== null) {
                $query = $searchFilter($query, $search, $columnSearch);
            } else {
                $query = $this->searchFilter($query, $search, $columnSearch);
            }
        }

        $total = $query->count();
        $pages = ceil($total / $limit);

        $models = $query->skip(($page - 1) * $limit)
            ->take($limit)
            ->orderBy($orderBy, $orderDir)
            ->get();

        return [
            'collection' => $models,
            'total' => $total,
            'pages' => $pages,
        ];
    }

    // AQUI ESTA LA CORRECCION PRINCIPAL
    public function searchFilter($query, string $search, array|string $columns): Builder
    {
        $columns = is_array($columns) ? $columns : [$columns];

        return $query->where(function ($q) use ($search, $columns) {
            foreach ($columns as $column) {
                // Caso 1: Columnas con funciones SQL crudas (ej: unaccent, subconsultas de servidor)
                if (str_contains($column, '(')) {
                    if (!$this->isValidRawSearchExpression($column)) {
                        continue;
                    }

                    $q->orWhereRaw("unaccent(CAST($column AS TEXT)) ILIKE unaccent(?)", ['%' . $search . '%']);
                    continue;
                }

                // Caso 2: Columnas de Fecha y Hora (Datetime)
                // Detectamos 'creation_time', 'updated_at', etc.
                if (in_array($column, ['creation_time', 'date', 'created_at', 'updated_at']) || str_ends_with($column, '_time') || str_ends_with($column, '_at')) {
                    if (!$this->isValidColumnIdentifier($column)) {
                        continue;
                    }

                    // Opción A: Busca coincidencias con el formato visual (DD/MM/YYYY 12H AM/PM)
                    // Esto permite buscar "03/01/2026" y también "03/01/2026 02:00 PM"
                    $q->orWhereRaw("TO_CHAR($column, 'DD/MM/YYYY HH12:MI:SS AM') ILIKE ?", ['%' . $search . '%']);

                    // Opción B: Mantiene compatibilidad con formato ISO (YYYY-MM-DD) por si acaso
                    $q->orWhereRaw("TO_CHAR($column, 'YYYY-MM-DD HH24:MI:SS') ILIKE ?", ['%' . $search . '%']);

                    continue;
                }

                // Caso 3: Relaciones (tablas.columna)
                if (str_contains($column, '.')) {
                    [$relation, $field] = explode('.', $column, 2);

                    if (!$this->isValidColumnIdentifier($relation) || !$this->isValidColumnIdentifier($field)) {
                        continue;
                    }

                    $q->orWhereHas($relation, function ($subQuery) use ($field, $search) {
                        $subQuery->whereRaw("unaccent(CAST($field AS TEXT)) ILIKE unaccent(?)", ['%' . $search . '%']);
                    });

                    continue;
                }

                // Caso 4: Columnas normales de texto
                if (!$this->isValidColumnIdentifier($column)) {
                    continue;
                }

                $q->orWhereRaw("unaccent(CAST($column AS TEXT)) ILIKE unaccent(?)", ['%' . $search . '%']);
            }
        });
    }

    private function isValidColumnIdentifier(string $identifier): bool
    {
        return (bool) preg_match('/^[a-zA-Z0-9_]+$/', $identifier);
    }

    private function isValidRawSearchExpression(string $expression): bool
    {
        $expression = trim($expression);

        if (!str_starts_with($expression, '(SELECT') || !str_ends_with($expression, ')')) {
            return false;
        }

        $forbiddenPatterns = [';', '--', '/*', '*/', 'DROP', 'DELETE', 'INSERT', 'UPDATE', 'UNION', 'EXEC', 'xp_'];

        foreach ($forbiddenPatterns as $pattern) {
            if (stripos($expression, $pattern) !== false) {
                return false;
            }
        }

        return true;
    }

    public function filters($query, array $filters = [])
    {
        // ... (tu lógica existente de filters) ...
        foreach ($filters as $column => $value) {
            if (str_contains((string) $value, ',')) {
                $values = explode(',', $value);
                $nonNullValues = [];
                $includesNull = false;
                foreach ($values as $v) {
                    if (strtolower($v) === 'null') {
                        $includesNull = true;
                    } elseif (!empty($v)) {
                        $nonNullValues[] = $v;
                    }
                }

                $query->where(function ($q) use ($column, $nonNullValues, $includesNull) {
                    if (!empty($nonNullValues)) {
                        $q->whereIn($column, $nonNullValues);
                    }
                    if ($includesNull) {
                        $q->orWhereNull($column);
                    }
                });

            } else {
                if (strtolower((string) $value) === 'null') {
                    $query->whereNull($column);
                } else {
                    $query->where($column, $value);
                }
            }
        }
        return $query;
    }
}
