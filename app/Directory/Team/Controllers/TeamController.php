<?php

namespace App\Directory\Team\Controllers;

use App\Administration\User\Models\User;
use App\Directory\Team\Models\Team;
use App\Directory\Team\Requests\TeamCreateRequest;
use App\Directory\Team\Requests\TeamUpdateRequest;
use App\Directory\Team\Resources\TeamResource;
use App\Directory\Team\Services\TeamService;
use App\Inventory\Warehouse\Models\Warehouse;
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
        return DB::transaction(function () use ($request): JsonResponse {
            $data = $this->sharedService->convertCamelToSnake($request->validated());
            if (! array_key_exists('salary', $data) || $data['salary'] === null || $data['salary'] === '') {
                $data['salary'] = 0;
            }

            /** @var Team $team */
            $team = $this->teamService->create($data);

            $warehouse = Warehouse::query()->findOrFail((int) $team->warehouse_id);
            $tenantId = $warehouse->tenant_id;
            if ($tenantId === null) {
                throw new \InvalidArgumentException('La tienda seleccionada no tiene un cliente (tenant) asociado.');
            }

            $plainPassword = "password";

            $email = sprintf('%s.%s@novedadesmaritex.net.pe', $team->name, $team->surname);
            $username = sprintf('%s.%s', $team->name, $team->surname);

            $user = User::query()->create([
                'username' => $username,
                'email' => $email,
                'name' => $team->name,
                'surname' => $team->surname,
                'password' => $plainPassword,
                'warehouse_id' => $team->warehouse_id,
                'tenant_id' => $tenantId,
            ]);

            $user->syncRoles(['Vendedora']);

            $team->user_id = $user->id;
            $team->save();

            $team->load('user');

            return response()->json([
                'message' => 'Colaborador y usuario de acceso creados.',
                'data' => new TeamResource($team),
                'login' => [
                    'email' => $user->email,
                    'username' => $user->username,
                    'temporaryPassword' => $plainPassword,
                ],
            ], 201);
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
        $team->load('user');

        return response()->json(new TeamResource($team));
    }

    public function getAll(GetAllRequest $request): JsonResponse
    {
        $query = $this->sharedService->query(
            request:      $request,
            entityName:   'Directory\\Team',
            modelName:    'Team',
            columnSearch: ['id', 'dni', 'name', 'surname', 'salary']
        );

        return response()->json(new GetAllCollection(
            TeamResource::collection($query['collection']),
            $query['total'],
            $query['pages']
        ));
    }
}
