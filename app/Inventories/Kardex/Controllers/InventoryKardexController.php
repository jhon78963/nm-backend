<?php

namespace App\Inventories\Kardex\Controllers;

use App\Inventories\Kardex\Requests\InventoryKardexReportRequest;
use App\Inventories\Kardex\Resources\InventoryKardexMovementResource;
use App\Inventories\Kardex\Services\InventoryKardexReportService;
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
