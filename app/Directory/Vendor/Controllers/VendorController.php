<?php

namespace App\Directory\Vendor\Controllers;

use App\Directory\Vendor\Models\Vendor;
use App\Directory\Vendor\Requests\VendorCreateRequest;
use App\Directory\Vendor\Requests\VendorUpdateRequest;
use App\Directory\Vendor\Resources\VendorResource;
use App\Directory\Vendor\Services\VendorService;
use App\Shared\Foundation\Controllers\Controller;
use App\Shared\Foundation\Requests\GetAllRequest;
use App\Shared\Foundation\Resources\GetAllCollection;
use App\Shared\Foundation\Services\SharedService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class VendorController extends Controller
{
    public function __construct(
        protected VendorService $vendorService,
        protected SharedService $sharedService,
    ) {}

    public function create(VendorCreateRequest $request): JsonResponse
    {
        return DB::transaction(function () use ($request) {
            $data = $this->sharedService->convertCamelToSnake($request->validated());
            $this->vendorService->create($data);

            return response()->json(['message' => 'Vendor created.'], 201);
        });
    }

    public function update(VendorUpdateRequest $request, Vendor $vendor): JsonResponse
    {
        return DB::transaction(function () use ($request, $vendor): JsonResponse {
            $this->vendorService->validate($vendor, 'Vendor');
            $data = $this->sharedService->convertCamelToSnake($request->validated());
            $this->vendorService->update($vendor, $data);

            return response()->json(['message' => 'Vendor updated.'], 200);
        });
    }

    public function delete(Vendor $vendor): JsonResponse
    {
        return DB::transaction(function () use ($vendor) {
            $this->vendorService->validate($vendor, 'Vendor');
            $this->vendorService->delete($vendor);

            return response()->json(['message' => 'Vendor deleted.'], 200);
        });
    }

    public function get(Vendor $vendor): JsonResponse
    {
        $this->vendorService->validate($vendor, 'Vendor');

        return response()->json(new VendorResource($vendor));
    }

    public function getAll(GetAllRequest $request): JsonResponse
    {
        $query = $this->sharedService->query(
            request:      $request,
            entityName:   'Directory\\Vendor',
            modelName:    'Vendor',
            columnSearch: ['id', 'name', 'address', 'local', 'balance', 'phone'],
        );

        return response()->json(new GetAllCollection(
            VendorResource::collection($query['collection']),
            $query['total'],
            $query['pages']
        ));
    }
}
