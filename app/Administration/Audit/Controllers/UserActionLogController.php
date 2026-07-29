<?php

namespace App\Administration\Audit\Controllers;

use App\Administration\Audit\Models\UserActionLog;
use App\Administration\Audit\Resources\UserActionLogResource;
use App\Administration\Audit\Support\AuditActions;
use App\Shared\Foundation\Controllers\Controller;
use App\Shared\Foundation\Requests\GetAllRequest;
use App\Shared\Foundation\Resources\GetAllCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

class UserActionLogController extends Controller
{
    /** @var list<string> */
    private const ACTION_GROUPS = [
        'role',
        'user',
        'team_payment',
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

        $query = UserActionLog::query()
            ->with(['user', 'team'])
            ->orderByDesc('creation_time');

        if (! auth()->user()->hasRole('Super Admin')) {
            $query->where('warehouse_id', auth()->user()->warehouse_id);
        }

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('action', 'ilike', '%'.$search.'%')
                    ->orWhere('description', 'ilike', '%'.$search.'%');
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

    private function isAllowedAction(string $action): bool
    {
        return in_array($action, [
            AuditActions::ROLE_CREATED,
            AuditActions::ROLE_UPDATED,
            AuditActions::ROLE_DELETED,
            AuditActions::ROLE_PERMISSIONS_SYNCED,
            AuditActions::USER_CREATED,
            AuditActions::USER_UPDATED,
            AuditActions::USER_DELETED,
            AuditActions::USER_PASSWORD_RESET,
            AuditActions::TEAM_PAYMENT_CREATED,
            AuditActions::TEAM_PAYMENT_UPDATED,
            AuditActions::TEAM_PAYMENT_DELETED,
            AuditActions::SALE_DELETED,
            AuditActions::CASHFLOW_CREATED,
            AuditActions::CASHFLOW_UPDATED,
            AuditActions::CASHFLOW_DELETED,
        ], true);
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
