<?php

namespace App\Finance\FinancialSummary\Controllers;

use App\Finance\FinancialSummary\Services\FinancialSummaryService;
use App\Shared\Foundation\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class FinancialSummaryController extends Controller
{
    protected FinancialSummaryService $financialSummaryService;

    public function __construct(FinancialSummaryService $financialSummaryService)
    {
        $this->financialSummaryService = $financialSummaryService;
    }

    public function getSummary(): JsonResponse
    {
        return response()->json(
            $this->financialSummaryService->getSummary()
        );
    }
}
