<?php

namespace App\Finance\Expense\Controllers;

use App\Finance\Expense\Models\Expense;
use App\Finance\Expense\Requests\ExpenseCreateRequest;
use App\Finance\Expense\Requests\ExpenseUpdateRequest;
use App\Finance\Expense\Resources\ExpenseResource;
use App\Finance\Expense\Services\ExpenseService;
use App\Shared\Foundation\Controllers\Controller;
use App\Shared\Foundation\Requests\GetAllRequest;
use App\Shared\Foundation\Resources\GetAllCollection;
use App\Shared\Foundation\Services\SharedService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class ExpenseController extends Controller
{
    public function __construct(
        protected ExpenseService $expenseService,
        protected SharedService $sharedService,
    ) {
    }

    public function create(ExpenseCreateRequest $request): JsonResponse
    {
        return DB::transaction(function () use ($request) {
            $data = $this->sharedService->convertCamelToSnake($request->validated());
            $this->expenseService->create($data);

            return response()->json(['message' => 'Expense created.'], 201);
        });
    }

    public function update(ExpenseUpdateRequest $request, Expense $expense): JsonResponse
    {
        return DB::transaction(function () use ($request, $expense): JsonResponse {
            $this->expenseService->validate($expense, 'Expense');
            $data = $this->sharedService->convertCamelToSnake($request->validated());
            $this->expenseService->update($expense, $data);

            return response()->json(['message' => 'Expense updated.'], 200);
        });
    }

    public function delete(Expense $expense): JsonResponse
    {
        return DB::transaction(function () use ($expense): JsonResponse {
            $this->expenseService->validate($expense, 'Expense');
            $this->expenseService->delete($expense);
            return response()->json(['message' => 'Expense deleted.'], 200);
        });
    }

    public function get(Expense $expense): JsonResponse
    {
        $this->expenseService->validate($expense, 'Expense');

        return response()->json(new ExpenseResource($expense));
    }

    public function getAll(GetAllRequest $request): JsonResponse
    {
        $query = $this->sharedService->query(
            request: $request,
            entityName: 'Finance\\Expense',
            modelName: 'Expense',
            columnSearch: ['id', 'expense_date', 'description', 'category', 'amount', 'payment_method', 'reference_code'],
            orderBy: 'expense_date',
            orderDir: 'desc',
        );

        return response()->json(new GetAllCollection(
            ExpenseResource::collection($query['collection']),
            $query['total'],
            $query['pages'],
        ));
    }
}
