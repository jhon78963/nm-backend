<?php

namespace App\Size\Controllers;

use App\Size\Models\Size;
use App\Size\Requests\SizeCreateRequest;
use App\Size\Requests\SizeUpdateRequest;
use App\Size\Resources\AutocompleteSizeResource;
use App\Size\Resources\SizeResource;
use App\Size\Services\SizeService;
use App\Shared\Controllers\Controller;
use App\Shared\Requests\GetAllRequest;
use App\Shared\Resources\GetAllCollection;
use App\Shared\Services\SharedService;
use Illuminate\Http\JsonResponse;
use DB;

class SizeController extends Controller
{
    protected SizeService $sizeService;
    protected SharedService $sharedService;

    public function __construct(SizeService $sizeService, SharedService $sharedService)
    {
        $this->sizeService = $sizeService;
        $this->sharedService = $sharedService;
    }

    public function create(SizeCreateRequest $request): JsonResponse
    {
        DB::beginTransaction();
        try {
            $newSize = $this->sharedService->convertCamelToSnake($request->validated());
            $size = $this->sizeService->create($newSize);
            DB::commit();
            return response()->json([
                'message' => 'Size created.',
                'item' => [
                    'id' => $size->id,
                    'value' => $size->description,
                ],
            ], 201);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(['error' =>  $e->getMessage()]);
        }
    }

    public function delete(Size $size): JsonResponse
    {
        DB::beginTransaction();
        try {
            $sizeValidated = $this->sizeService->validate($size, 'Size');
            $this->sizeService->delete($sizeValidated);
            DB::commit();
            return response()->json(['message' => 'Size deleted.']);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(['error' =>  $e->getMessage()]);
        }
    }

    public function get(Size $size): JsonResponse
    {
        $sizeValidated = $this->sizeService->validate($size, 'Size');
        return response()->json(new SizeResource($sizeValidated));
    }

    public function getAutocomplete(Size $size): JsonResponse
    {
        $sizeValidated = $this->sizeService->validate($size, 'Size');
        return response()->json(new AutocompleteSizeResource($sizeValidated));
    }

    public function getAll(GetAllRequest $request): JsonResponse
    {
        $query = $this->sharedService->query(
            $request,
            'Size',
            'Size',
            'description'
        );
        return response()->json(new GetAllCollection(
            SizeResource::collection($query['collection']),
            $query['total'],
            $query['pages'],
        ));
    }

    public function getAllAutocomplete(GetAllRequest $request): JsonResponse
    {
        $query = $this->sharedService->query(
            $request,
            'Size',
            'Size',
            'description'
        );
        return response()->json(
            AutocompleteSizeResource::collection($query['collection'])
        );
    }

    public function update(SizeUpdateRequest $request, Size $size): JsonResponse
    {
        DB::beginTransaction();
        try {
            $editSize = $this->sharedService->convertCamelToSnake($request->validated());
            $sizeValidated = $this->sizeService->validate($size, 'Size');
            $this->sizeService->update($sizeValidated, $editSize);
            DB::commit();
            return response()->json(['message' => 'Size updated.']);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(['error' =>  $e->getMessage()]);
        }
    }
}
