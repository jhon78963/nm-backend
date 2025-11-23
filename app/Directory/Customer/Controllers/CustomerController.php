<?php

namespace App\Directory\Customer\Controllers;

use App\Directory\Customer\Models\Customer;
use App\Directory\Customer\Requests\CustomerCreateRequest;
use App\Directory\Customer\Requests\CustomerUpdateRequest;
use App\Directory\Customer\Resources\CustomerResource;
use App\Directory\Customer\Services\CustomerService;
use App\Shared\Foundation\Controllers\Controller;
use App\Shared\Foundation\Requests\GetAllRequest;
use App\Shared\Foundation\Resources\GetAllCollection;
use App\Shared\Foundation\Services\SharedService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class CustomerController extends Controller
{
    public function __construct(
        protected CustomerService $customerService,
        protected SharedService $sharedService,
    ) {}

    public function create(CustomerCreateRequest $request): JsonResponse
    {
        return DB::transaction(function () use ($request) {
            $data = $this->sharedService->convertCamelToSnake($request->validated());
            $this->customerService->create($data);

            return response()->json(['message' => 'Customer created.'], 201);
        });
    }

    public function update(CustomerUpdateRequest $request, Customer $customer): JsonResponse
    {
        return DB::transaction(function () use ($request, $customer): JsonResponse {
            $this->customerService->validate($customer, 'Customer');
            $data = $this->sharedService->convertCamelToSnake($request->validated());
            $this->customerService->update($customer, $data);

            return response()->json(['message' => 'Customer updated.'], 200);
        });
    }

    public function delete(Customer $customer): JsonResponse
    {
        return DB::transaction(function () use ($customer) {
            $this->customerService->validate($customer, 'Customer');
            $this->customerService->delete($customer);

            return response()->json(['message' => 'Customer deleted.'], 200);
        });
    }

    public function get(Customer $customer): JsonResponse
    {
        $this->customerService->validate($customer, 'Customer');

        return response()->json(new CustomerResource($customer));
    }

    public function getAll(GetAllRequest $request): JsonResponse
    {
        $query = $this->sharedService->query(
            request:      $request,
            entityName:   'Directory\\Customer',
            modelName:    'Customer',
            columnSearch: ['id', 'name']
        );

        return response()->json(new GetAllCollection(
            CustomerResource::collection($query['collection']),
            $query['total'],
            $query['pages']
        ));
    }
}
