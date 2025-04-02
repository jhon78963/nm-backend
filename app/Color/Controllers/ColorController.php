<?php

namespace App\Color\Controllers;

use App\Color\Models\Color;
use App\Color\Requests\ColorCreateRequest;
use App\Color\Requests\ColorUpdateRequest;
use App\Color\Resources\AutocompleteColorResource;
use App\Color\Resources\ColorResource;
use App\Color\Services\ColorService;
use App\Shared\Controllers\Controller;
use App\Shared\Requests\GetAllRequest;
use App\Shared\Resources\GetAllCollection;
use App\Shared\Services\SharedService;
use Illuminate\Http\JsonResponse;
use DB;

class ColorController extends Controller
{
    protected ColorService $colorService;
    protected SharedService $sharedService;

    public function __construct(ColorService $colorService, SharedService $sharedService)
    {
        $this->colorService = $colorService;
        $this->sharedService = $sharedService;
    }

    public function create(ColorCreateRequest $request): JsonResponse
    {
        DB::beginTransaction();
        try {
            $newColor = $this->sharedService->convertCamelToSnake($request->validated());
            $color = $this->colorService->create($newColor);
            DB::commit();
            return response()->json([
                'message' => 'Color created.',
                'item' => [
                    'id' => $color->id,
                    'value' => $color->description,
                ],
            ], 201);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(['error' =>  $e->getMessage()]);
        }
    }

    public function delete(Color $color): JsonResponse
    {
        DB::beginTransaction();
        try {
            $colorValidated = $this->colorService->validate($color, 'Color');
            $this->colorService->delete($colorValidated);
            DB::commit();
            return response()->json(['message' => 'Color deleted.']);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(['error' =>  $e->getMessage()]);
        }
    }

    public function get(Color $color): JsonResponse
    {
        $colorValidated = $this->colorService->validate($color, 'Color');
        return response()->json(new ColorResource($colorValidated));
    }

    public function getAll(GetAllRequest $request): JsonResponse
    {
        $query = $this->sharedService->query(
            $request,
            'Color',
            'Color',
            'description'
        );
        return response()->json(new GetAllCollection(
            ColorResource::collection($query['collection']),
            $query['total'],
            $query['pages'],
        ));
    }

    public function getAllAutocomplete(GetAllRequest $request): JsonResponse
    {
        $query = $this->sharedService->query(
            $request,
            'Color',
            'Color',
            'description'
        );
        return response()->json(
            AutocompleteColorResource::collection($query['collection'])
        );
    }

    public function update(ColorUpdateRequest $request, Color $color): JsonResponse
    {
        DB::beginTransaction();
        try {
            $editColor = $this->sharedService->convertCamelToSnake($request->validated());
            $colorValidated = $this->colorService->validate($color, 'Color');
            $this->colorService->update($colorValidated, $editColor);
            DB::commit();
            return response()->json(['message' => 'Color updated.']);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(['error' =>  $e->getMessage()]);
        }
    }
}
