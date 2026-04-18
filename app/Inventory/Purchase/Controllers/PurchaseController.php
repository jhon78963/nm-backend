<?php

namespace App\Inventory\Purchase\Controllers;

use App\Inventory\Purchase\Requests\PurchaseBulkRequest;
use App\Inventory\Purchase\Services\PurchaseBulkService;
use App\Shared\Foundation\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class PurchaseController extends Controller
{
    public function __construct(
        protected PurchaseBulkService $purchaseBulkService,
    ) {
    }

    public function registerBulk(PurchaseBulkRequest $request): JsonResponse
    {
        $this->purchaseBulkService->handle($request->validated());

        return response()->json([
            'message' => 'Compra registrada e inventario actualizado.',
        ], 201);
    }
}
