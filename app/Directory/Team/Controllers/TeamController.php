<?php

namespace App\Directory\Team\Controllers;

use App\Directory\Team\Models\Team;
use App\Directory\Team\Requests\TeamCreateRequest;
use App\Directory\Team\Requests\TeamUpdateRequest;
use App\Directory\Team\Resources\TeamResource;
use App\Directory\Team\Services\TeamService;
use App\Shared\Foundation\Controllers\Controller;
use App\Shared\Foundation\Requests\GetAllRequest;
use App\Shared\Foundation\Resources\GetAllCollection;
use App\Shared\Foundation\Services\SharedService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class TeamController extends Controller
{
    public function __construct(
        protected TeamService $teamService,
        protected SharedService $sharedService,
    ) {}

    public function create(TeamCreateRequest $request): JsonResponse
    {
        return DB::transaction(function () use ($request) {
            $data = $this->sharedService->convertCamelToSnake($request->validated());
            $this->teamService->create($data);

            return response()->json(['message' => 'Team created.'], 201);
        });
    }

    public function update(TeamUpdateRequest $request, Team $team): JsonResponse
    {
        return DB::transaction(function () use ($request, $team): JsonResponse {
            $this->teamService->validate($team, 'Team');
            $data = $this->sharedService->convertCamelToSnake($request->validated());
            $this->teamService->update($team, $data);

            return response()->json(['message' => 'Team updated.'], 200);
        });
    }

    public function delete(Team $team): JsonResponse
    {
        return DB::transaction(function () use ($team) {
            $this->teamService->validate($team, 'Team');
            $this->teamService->delete($team);

            return response()->json(['message' => 'Team deleted.'], 200);
        });
    }

    public function get(Team $team): JsonResponse
    {
        $this->teamService->validate($team, 'Team');

        return response()->json(new TeamResource($team));
    }

    public function getAll(GetAllRequest $request): JsonResponse
    {
        $query = $this->sharedService->query(
            request:      $request,
            entityName:   'Directory\\Team',
            modelName:    'Team',
            columnSearch: ['id', 'name']
        );

        return response()->json(new GetAllCollection(
            TeamResource::collection($query['collection']),
            $query['total'],
            $query['pages']
        ));
    }
}
