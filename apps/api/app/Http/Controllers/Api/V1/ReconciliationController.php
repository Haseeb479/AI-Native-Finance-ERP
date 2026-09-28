<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Accounting\Reconciliation\Services\SubledgerReconciliationService;
use App\Domain\Organization\Models\Organization;
use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReconciliationController extends Controller
{
    public function __construct(
        protected SubledgerReconciliationService $reconciliationService
    ) {}

    /**
     * Run automated subledger to GL reconciliation report for an organization.
     * GET /api/v1/organizations/{organization}/reconciliations/subledger
     */
    public function subledgerReport(Request $request, Organization $organization): JsonResponse
    {
        $asOfDate = $request->has('as_of_date')
            ? Carbon::parse($request->query('as_of_date'))
            : now();

        $report = $this->reconciliationService->runFullReconciliation($organization, $asOfDate);

        return response()->json([
            'data' => $report,
        ]);
    }
}
