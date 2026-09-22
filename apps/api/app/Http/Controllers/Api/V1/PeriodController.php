<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Accounting\Period\Models\AccountingPeriod;
use App\Domain\Accounting\Period\Models\FiscalYear;
use App\Domain\Accounting\Period\Services\PeriodManager;
use App\Domain\Organization\Models\Organization;
use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\CreateFiscalYearRequest;
use App\Http\Requests\Accounting\ReopenPeriodRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PeriodController extends Controller
{
    public function __construct(private readonly PeriodManager $periodManager)
    {
    }

    private function getAuthorizedOrganization(Request $request, string $orgId): ?Organization
    {
        return $request->user()?->organizations()
            ->where('organizations.id', $orgId)
            ->first();
    }

    private function canManagePeriods(Request $request, Organization $organization): bool
    {
        $user = $request->user();
        $role = $user->roleInOrganization($organization);

        if (in_array($role, ['owner', 'admin', 'finance_manager'])) {
            return true;
        }

        return $user->hasPermissionInOrganization('ledger:close_period', $organization);
    }

    /**
     * List all fiscal years for the organization.
     */
    public function indexFiscalYears(Request $request, string $orgId): JsonResponse
    {
        $organization = $this->getAuthorizedOrganization($request, $orgId);

        if (! $organization) {
            return response()->json([
                'data' => null,
                'meta' => ['timestamp' => now()->toISOString()],
                'errors' => [
                    [
                        'code' => 'ORGANIZATION_NOT_FOUND',
                        'message' => 'Organization not found or access denied.',
                    ],
                ],
            ], 404);
        }

        $fiscalYears = FiscalYear::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->with(['periods', 'closedByUser:id,name,email'])
            ->orderBy('start_date', 'desc')
            ->get();

        return response()->json([
            'data' => $fiscalYears,
            'meta' => [
                'total' => $fiscalYears->count(),
                'organization_id' => $organization->id,
                'timestamp' => now()->toISOString(),
            ],
            'errors' => [],
        ]);
    }

    /**
     * Generate a new fiscal year with 12 monthly accounting periods.
     */
    public function storeFiscalYear(CreateFiscalYearRequest $request, string $orgId): JsonResponse
    {
        $organization = $this->getAuthorizedOrganization($request, $orgId);

        if (! $organization) {
            return response()->json([
                'data' => null,
                'meta' => ['timestamp' => now()->toISOString()],
                'errors' => [
                    [
                        'code' => 'ORGANIZATION_NOT_FOUND',
                        'message' => 'Organization not found or access denied.',
                    ],
                ],
            ], 404);
        }

        if (! $this->canManagePeriods($request, $organization)) {
            return response()->json([
                'data' => null,
                'meta' => ['timestamp' => now()->toISOString()],
                'errors' => [
                    [
                        'code' => 'FORBIDDEN',
                        'message' => 'You do not have permission to manage fiscal years.',
                    ],
                ],
            ], 403);
        }

        $startYear = (int) $request->validated('start_year');
        $fiscalYear = $this->periodManager->generateFiscalYear($organization, $startYear);

        return response()->json([
            'data' => $fiscalYear,
            'meta' => [
                'message' => "Fiscal year {$fiscalYear->name} with 12 periods generated successfully",
                'timestamp' => now()->toISOString(),
            ],
            'errors' => [],
        ], 201);
    }

    /**
     * List all accounting periods.
     */
    public function indexPeriods(Request $request, string $orgId): JsonResponse
    {
        $organization = $this->getAuthorizedOrganization($request, $orgId);

        if (! $organization) {
            return response()->json([
                'data' => null,
                'meta' => ['timestamp' => now()->toISOString()],
                'errors' => [
                    [
                        'code' => 'ORGANIZATION_NOT_FOUND',
                        'message' => 'Organization not found or access denied.',
                    ],
                ],
            ], 404);
        }

        $query = AccountingPeriod::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->with(['fiscalYear:id,name', 'closedByUser:id,name,email', 'reopenedByUser:id,name,email']);

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('fiscal_year_id')) {
            $query->where('fiscal_year_id', $request->query('fiscal_year_id'));
        }

        $periods = $query->orderBy('start_date')->get();

        return response()->json([
            'data' => $periods,
            'meta' => [
                'total' => $periods->count(),
                'organization_id' => $organization->id,
                'timestamp' => now()->toISOString(),
            ],
            'errors' => [],
        ]);
    }

    /**
     * Close an accounting period.
     */
    public function close(Request $request, string $orgId, string $periodId): JsonResponse
    {
        $organization = $this->getAuthorizedOrganization($request, $orgId);

        if (! $organization) {
            return response()->json([
                'data' => null,
                'meta' => ['timestamp' => now()->toISOString()],
                'errors' => [
                    [
                        'code' => 'ORGANIZATION_NOT_FOUND',
                        'message' => 'Organization not found or access denied.',
                    ],
                ],
            ], 404);
        }

        if (! $this->canManagePeriods($request, $organization)) {
            return response()->json([
                'data' => null,
                'meta' => ['timestamp' => now()->toISOString()],
                'errors' => [
                    [
                        'code' => 'FORBIDDEN',
                        'message' => 'You do not have permission to close accounting periods.',
                    ],
                ],
            ], 403);
        }

        $period = AccountingPeriod::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->findOrFail($periodId);

        $closedPeriod = $this->periodManager->closePeriod($period, $request->user());

        return response()->json([
            'data' => $closedPeriod,
            'meta' => [
                'message' => "Period {$closedPeriod->name} closed successfully.",
                'timestamp' => now()->toISOString(),
            ],
            'errors' => [],
        ]);
    }

    /**
     * Reopen an accounting period.
     */
    public function reopen(ReopenPeriodRequest $request, string $orgId, string $periodId): JsonResponse
    {
        $organization = $this->getAuthorizedOrganization($request, $orgId);

        if (! $organization) {
            return response()->json([
                'data' => null,
                'meta' => ['timestamp' => now()->toISOString()],
                'errors' => [
                    [
                        'code' => 'ORGANIZATION_NOT_FOUND',
                        'message' => 'Organization not found or access denied.',
                    ],
                ],
            ], 404);
        }

        if (! $this->canManagePeriods($request, $organization)) {
            return response()->json([
                'data' => null,
                'meta' => ['timestamp' => now()->toISOString()],
                'errors' => [
                    [
                        'code' => 'FORBIDDEN',
                        'message' => 'You do not have permission to reopen accounting periods.',
                    ],
                ],
            ], 403);
        }

        $period = AccountingPeriod::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->findOrFail($periodId);

        $reopenedPeriod = $this->periodManager->reopenPeriod($period, $request->user(), $request->validated('reason'));

        return response()->json([
            'data' => $reopenedPeriod,
            'meta' => [
                'message' => "Period {$reopenedPeriod->name} reopened successfully.",
                'timestamp' => now()->toISOString(),
            ],
            'errors' => [],
        ]);
    }

    /**
     * Lock an accounting period permanently.
     */
    public function lock(Request $request, string $orgId, string $periodId): JsonResponse
    {
        $organization = $this->getAuthorizedOrganization($request, $orgId);

        if (! $organization) {
            return response()->json([
                'data' => null,
                'meta' => ['timestamp' => now()->toISOString()],
                'errors' => [
                    [
                        'code' => 'ORGANIZATION_NOT_FOUND',
                        'message' => 'Organization not found or access denied.',
                    ],
                ],
            ], 404);
        }

        if (! $this->canManagePeriods($request, $organization)) {
            return response()->json([
                'data' => null,
                'meta' => ['timestamp' => now()->toISOString()],
                'errors' => [
                    [
                        'code' => 'FORBIDDEN',
                        'message' => 'You do not have permission to lock accounting periods.',
                    ],
                ],
            ], 403);
        }

        $period = AccountingPeriod::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->findOrFail($periodId);

        $lockedPeriod = $this->periodManager->lockPeriod($period, $request->user());

        return response()->json([
            'data' => $lockedPeriod,
            'meta' => [
                'message' => "Period {$lockedPeriod->name} locked successfully.",
                'timestamp' => now()->toISOString(),
            ],
            'errors' => [],
        ]);
    }
}
