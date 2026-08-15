<?php

namespace App\Dashboard\Controllers;

use App\Dashboard\Services\DashboardMetricsService;
use App\Shared\Foundation\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function __construct(
        protected DashboardMetricsService $dashboardMetricsService,
    ) {
    }

    public function metrics(): JsonResponse
    {
        return response()->json($this->dashboardMetricsService->getMetrics());
    }
}
