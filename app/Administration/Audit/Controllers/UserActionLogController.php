<?php

namespace App\Administration\Audit\Controllers;

use App\Administration\Audit\Models\UserActionLog;
use App\Administration\Audit\Resources\UserActionLogResource;
use App\Shared\Foundation\Controllers\Controller;
use App\Shared\Foundation\Requests\GetAllRequest;
use App\Shared\Foundation\Resources\GetAllCollection;
use Illuminate\Http\JsonResponse;

class UserActionLogController extends Controller
{
    public function getAll(GetAllRequest $request): JsonResponse
    {
        $limit = (int) $request->query('limit', 10);
        $page = (int) $request->query('page', 1);
        $search = (string) $request->query('search', '');

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
}
