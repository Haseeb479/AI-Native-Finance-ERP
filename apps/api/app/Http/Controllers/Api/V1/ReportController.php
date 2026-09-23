<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Organization\Models\Organization;
use App\Domain\Reporting\Services\ReportingService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function __construct(protected ReportingService $reportingService) {}

    private function getAuthorizedOrganization(Request $request, string $orgId): ?Organization
    {
        return $request->user()?->organizations()
            ->where('organizations.id', $orgId)
            ->first();
    }

    private function unauthorized(): JsonResponse
    {
        return response()->json([
            'data'   => null,
            'meta'   => ['timestamp' => now()->toISOString()],
            'errors' => [['code' => 'ORGANIZATION_NOT_FOUND', 'message' => 'Organization not found or access denied.']],
        ], 404);
    }

    /**
     * Trial Balance
     * GET /api/v1/organizations/{orgId}/reports/trial-balance?as_of=2025-09-30
     */
    public function trialBalance(Request $request, string $orgId): JsonResponse
    {
        $org = $this->getAuthorizedOrganization($request, $orgId);
        if (!$org) return $this->unauthorized();

        $asOf = $request->query('as_of', now()->toDateString());

        $report = $this->reportingService->trialBalance($org, $asOf);

        return response()->json([
            'data' => $report,
            'meta' => [
                'report'          => 'trial_balance',
                'organization_id' => $orgId,
                'as_of_date'      => $asOf,
                'generated_at'    => now()->toISOString(),
            ],
            'errors' => [],
        ]);
    }

    /**
     * Profit & Loss
     * GET /api/v1/organizations/{orgId}/reports/profit-and-loss?from=2025-07-01&to=2025-09-30
     */
    public function profitAndLoss(Request $request, string $orgId): JsonResponse
    {
        $org = $this->getAuthorizedOrganization($request, $orgId);
        if (!$org) return $this->unauthorized();

        $request->validate([
            'from' => ['required', 'date'],
            'to'   => ['required', 'date', 'after_or_equal:from'],
        ]);

        $report = $this->reportingService->profitAndLoss(
            $org,
            $request->query('from'),
            $request->query('to')
        );

        return response()->json([
            'data' => $report,
            'meta' => [
                'report'          => 'profit_and_loss',
                'organization_id' => $orgId,
                'from_date'       => $request->query('from'),
                'to_date'         => $request->query('to'),
                'generated_at'    => now()->toISOString(),
            ],
            'errors' => [],
        ]);
    }

    /**
     * Balance Sheet
     * GET /api/v1/organizations/{orgId}/reports/balance-sheet?as_of=2025-09-30
     */
    public function balanceSheet(Request $request, string $orgId): JsonResponse
    {
        $org = $this->getAuthorizedOrganization($request, $orgId);
        if (!$org) return $this->unauthorized();

        $asOf = $request->query('as_of', now()->toDateString());

        $report = $this->reportingService->balanceSheet($org, $asOf);

        return response()->json([
            'data' => $report,
            'meta' => [
                'report'          => 'balance_sheet',
                'organization_id' => $orgId,
                'as_of_date'      => $asOf,
                'generated_at'    => now()->toISOString(),
            ],
            'errors' => [],
        ]);
    }

    /**
     * General Ledger
     * GET /api/v1/organizations/{orgId}/reports/general-ledger?from=2025-07-01&to=2025-09-30&account_id=...
     */
    public function generalLedger(Request $request, string $orgId): JsonResponse
    {
        $org = $this->getAuthorizedOrganization($request, $orgId);
        if (!$org) return $this->unauthorized();

        $request->validate([
            'from'       => ['required', 'date'],
            'to'         => ['required', 'date', 'after_or_equal:from'],
            'account_id' => ['nullable', 'uuid'],
        ]);

        $report = $this->reportingService->generalLedger(
            $org,
            $request->query('from'),
            $request->query('to'),
            $request->query('account_id')
        );

        return response()->json([
            'data' => $report,
            'meta' => [
                'report'          => 'general_ledger',
                'organization_id' => $orgId,
                'from_date'       => $request->query('from'),
                'to_date'         => $request->query('to'),
                'generated_at'    => now()->toISOString(),
            ],
            'errors' => [],
        ]);
    }

    /**
     * AR Aging
     * GET /api/v1/organizations/{orgId}/reports/ar-aging?as_of=2025-09-30
     */
    public function arAging(Request $request, string $orgId): JsonResponse
    {
        $org = $this->getAuthorizedOrganization($request, $orgId);
        if (!$org) return $this->unauthorized();

        $asOf = $request->query('as_of', now()->toDateString());

        $report = $this->reportingService->arAging($org, $asOf);

        return response()->json([
            'data' => $report,
            'meta' => [
                'report'          => 'ar_aging',
                'organization_id' => $orgId,
                'as_of_date'      => $asOf,
                'generated_at'    => now()->toISOString(),
            ],
            'errors' => [],
        ]);
    }

    /**
     * AP Aging
     * GET /api/v1/organizations/{orgId}/reports/ap-aging?as_of=2025-09-30
     */
    public function apAging(Request $request, string $orgId): JsonResponse
    {
        $org = $this->getAuthorizedOrganization($request, $orgId);
        if (!$org) return $this->unauthorized();

        $asOf = $request->query('as_of', now()->toDateString());

        $report = $this->reportingService->apAging($org, $asOf);

        return response()->json([
            'data' => $report,
            'meta' => [
                'report'          => 'ap_aging',
                'organization_id' => $orgId,
                'as_of_date'      => $asOf,
                'generated_at'    => now()->toISOString(),
            ],
            'errors' => [],
        ]);
    }
}
