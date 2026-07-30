<?php

namespace App\Administration\Audit\Controllers;

use App\Administration\Audit\Models\UserActionLog;
use App\Administration\Audit\Resources\UserActionLogResource;
use App\Administration\Audit\Support\ActionLogVisibility;
use App\Administration\User\Models\User;
use App\Shared\Foundation\Controllers\Controller;
use App\Shared\Foundation\Requests\GetAllRequest;
use App\Shared\Foundation\Resources\GetAllCollection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

class UserActionLogController extends Controller
{
    /** @var list<string> */
    private const ACTION_GROUPS = [
        'auth',
        'http',
        'role',
        'user',
        'team_payment',
        'pos',
        'sale',
        'cashflow',
    ];

    public function getAll(GetAllRequest $request): JsonResponse
    {
        $limit = (int) $request->query('limit', 10);
        $page = (int) $request->query('page', 1);
        $search = (string) $request->query('search', '');
        $action = (string) $request->query('action', '');
        $actionGroup = (string) $request->query('action_group', '');
        $startDate = (string) $request->query('start_date', '');
        $endDate = (string) $request->query('end_date', '');
        $userId = (int) $request->query('user_id', 0);

        /** @var User $actor */
        $actor = auth()->user();

        $query = UserActionLog::query()
            ->with(ActionLogVisibility::eagerLoads())
            ->orderByDesc('creation_time');

        ActionLogVisibility::apply($query, $actor);

        if ($userId > 0 && ActionLogVisibility::actorIsSuperAdmin($actor)) {
            $query->where('user_id', $userId);
        }

        if ($search !== '') {
            $like = '%'.$search.'%';
            $query->where(function (Builder $q) use ($like): void {
                $q->where('action', 'ilike', $like)
                    ->orWhere('description', 'ilike', $like)
                    ->orWhere('metadata->username', 'ilike', $like)
                    ->orWhereHas('user', function (Builder $userQuery) use ($like): void {
                        $userQuery->withoutGlobalScopes()
                            ->where(function (Builder $nameQuery) use ($like): void {
                                $nameQuery->where('name', 'ilike', $like)
                                    ->orWhere('surname', 'ilike', $like)
                                    ->orWhere('email', 'ilike', $like)
                                    ->orWhere('username', 'ilike', $like);
                            });
                    });
            });
        }

        if ($action !== '' && $this->isAllowedAction($action)) {
            $query->where('action', $action);
        } elseif ($actionGroup !== '' && in_array($actionGroup, self::ACTION_GROUPS, true)) {
            $query->where('action', 'like', $actionGroup.'.%');
        }

        if ($this->isValidDate($startDate)) {
            $query->where('creation_time', '>=', Carbon::parse($startDate)->startOfDay());
        }

        if ($this->isValidDate($endDate)) {
            $query->where('creation_time', '<=', Carbon::parse($endDate)->endOfDay());
        }

        $total = $query->count();
        $pages = $total > 0 ? (int) ceil($total / $limit) : 0;

        $collection = $query->skip(max(0, ($page - 1) * $limit))
            ->take($limit)
            ->get();

        return response()->json(new GetAllCollection(
            UserActionLogResource::collection($collection),
            $total,
            $pages,
        ));
    }

    /**
     * Valida que el código de acción sea un identificador semántico seguro
     * del formato "grupo.accion" o "grupo.subgrupo.accion".
     * Cubre tanto los AuditActions conocidos como los genéricos http.*.
     */
    private function isAllowedAction(string $action): bool
    {
        return preg_match('/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/', $action) === 1;
    }

    private function isValidDate(string $value): bool
    {
        if ($value === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return false;
        }

        [$year, $month, $day] = array_map('intval', explode('-', $value));

        return checkdate($month, $day, $year);
    }
}
