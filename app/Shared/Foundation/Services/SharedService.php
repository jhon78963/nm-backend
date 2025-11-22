<?php
namespace App\Shared\Foundation\Services;

use App\Shared\Foundation\Requests\GetAllRequest;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Arr;
use Carbon\Carbon;
use Str;

class SharedService
{
    private int $limit = 10;
    private int $page = 1;
    private string $search = '';
    private string $schedule = '';
    private int $gender = 0;
    private string $status = '';
    private string $startDate = '';
    private string $endDate = '';

    public function convertCamelToSnake(array $data): array
    {
        return Arr::mapWithKeys($data, function ($value, $key): array {
            return [Str::snake($key) => $value];
        });
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

    /**
     * @param GetAllRequest $request
     * @param string $entityName
     * @param string $modelName
     * @param array|string|null $columnSearch
     * @param array $filters
     * @param callable|null $extendQuery
     * @return array
     */
    public function query(
        GetAllRequest $request,
        string $entityName,
        string $modelName,
        array|string $columnSearch = null,
        array $filters = [],
        callable|null $extendQuery = null,
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
            $query = $this->searchFilter($query, $search, $columnSearch);
        }

        $total = $query->count();
        $pages = ceil($total / $limit);

        $models = $query->skip(($page - 1) * $limit)
            ->take($limit)
            ->orderBy('id', 'asc')
            ->get();

        return [
            'collection' => $models,
            'total' => $total,
            'pages' => $pages,
        ];
    }

    public function searchFilter($query, string $search, array|string $columns): Builder
    {
        $columns = is_array($columns) ? $columns : [$columns];

        return $query->where(function ($q) use ($search, $columns) {
            foreach ($columns as $column) {
                if (str_contains($column, '(')) {
                    $q->orWhereRaw("unaccent(CAST($column AS TEXT)) ILIKE unaccent(?)", ['%' . $search . '%']);
                    continue;
                }
                if (str_contains($column, '.')) {
                    [$relation, $field] = explode('.', $column, 2);

                    $q->orWhereHas($relation, function ($subQuery) use ($field, $search) {
                        $subQuery->whereRaw("unaccent(CAST($field AS TEXT)) ILIKE unaccent(?)", ['%' . $search . '%']);
                    });
                } else {
                    $q->orWhereRaw("unaccent(CAST($column AS TEXT)) ILIKE unaccent(?)", ['%' . $search . '%']);
                }
            }
        });
    }

    public function filters($query, array $filters = [])
    {
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
