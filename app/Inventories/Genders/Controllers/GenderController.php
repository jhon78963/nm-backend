<?php

namespace App\Inventories\Genders\Controllers;

use App\Inventories\Genders\Models\Gender;
use App\Inventories\Genders\Resources\GenderResource;
use App\Inventories\Genders\Services\GenderService;
use App\Shared\Foundation\Controllers\Controller;
use App\Shared\Foundation\Requests\GetAllRequest;
use App\Shared\Foundation\Resources\GetAllCollection;
use App\Shared\Foundation\Services\SharedService;
use Illuminate\Http\JsonResponse;

class GenderController extends Controller
{
    protected GenderService $genderService;
    protected SharedService $sharedService;

    public function __construct(GenderService $genderService, SharedService $sharedService)
    {
        $this->genderService = $genderService;
        $this->sharedService = $sharedService;
    }

    public function get(Gender $gender): JsonResponse
    {
        $genderValidated = $this->genderService->validate($gender, 'Gender');
        return response()->json(new GenderResource($genderValidated));
    }

    public function getAll(GetAllRequest $request): JsonResponse
    {
        $query = $this->sharedService->query(
            request: $request,
            entityName: 'Inventory\\Gender',
            modelName: 'Gender',
            columnSearch: 'name'
        );

        return response()->json(new GetAllCollection(
            GenderResource::collection($query['collection']),
            $query['total'],
            $query['pages'],
        ));
    }
}
