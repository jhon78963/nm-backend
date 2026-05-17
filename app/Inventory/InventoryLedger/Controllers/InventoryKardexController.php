<?php

namespace App\Inventory\InventoryLedger\Controllers;

use App\Inventory\InventoryLedger\Requests\InventoryKardexReportRequest;
use App\Inventory\InventoryLedger\Resources\InventoryKardexMovementResource;
use App\Inventory\InventoryLedger\Services\InventoryKardexReportService;
use App\Shared\Foundation\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class InventoryKardexController extends Controller
{
    public function index(
        InventoryKardexReportRequest $request,
        InventoryKardexReportService $reportService,
    ): JsonResponse {
        $report = $reportService->buildReport($request);

        return response()->json([
            'success' => true,
            'data' => [
                'meta' => $report['meta'],
                'movements' => InventoryKardexMovementResource::collection($report['movements'])->resolve(),
            ],
        ]);
    }
}
