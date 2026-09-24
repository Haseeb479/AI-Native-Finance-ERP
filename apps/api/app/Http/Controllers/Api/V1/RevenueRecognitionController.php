<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Organization\Models\Organization;
use App\Domain\Revenue\Models\RevenueContract;
use App\Domain\Revenue\Models\RevenueSchedule;
use App\Domain\Revenue\Services\RevenueRecognitionService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RevenueRecognitionController extends Controller
{
    public function __construct(
        protected RevenueRecognitionService $revenueService
    ) {}

    private function getAuthorizedOrganization(Request $request, string $orgId): ?Organization
    {
        return $request->user()?->organizations()
            ->where('organizations.id', $orgId)
            ->first();
    }

    private function unauthorized(): JsonResponse
    {
        return response()->json([
            'data' => null,
            'meta' => ['timestamp' => now()->toISOString()],
            'errors' => [['code' => 'ORGANIZATION_NOT_FOUND', 'message' => 'Organization not found or access denied.']],
        ], 404);
    }

    /**
     * List all revenue contracts for the organization.
     * GET /api/v1/organizations/{orgId}/revenue-contracts
     */
    public function indexContracts(Request $request, string $orgId): JsonResponse
    {
        $org = $this->getAuthorizedOrganization($request, $orgId);
        if (! $org) return $this->unauthorized();

        $query = RevenueContract::withoutGlobalScopes()
            ->where('organization_id', $org->id)
            ->with(['customer:id,name', 'deferredRevenueAccount:id,code,name', 'revenueAccount:id,code,name'])
            ->withCount('schedules')
            ->latest();

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        $contracts = $query->paginate(20);

        return response()->json([
            'data' => $contracts->items(),
            'meta' => [
                'current_page' => $contracts->currentPage(),
                'total' => $contracts->total(),
            ],
            'errors' => [],
        ]);
    }

    /**
     * Create a new revenue contract.
     * POST /api/v1/organizations/{orgId}/revenue-contracts
     */
    public function storeContract(Request $request, string $orgId): JsonResponse
    {
        $org = $this->getAuthorizedOrganization($request, $orgId);
        if (! $org) return $this->unauthorized();

        $validated = $request->validate([
            'customer_id' => ['required', 'uuid'],
            'title' => ['required', 'string', 'max:255'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'total_contract_value' => ['required', 'numeric', 'min:0.01'],
            'currency' => ['nullable', 'string', 'size:3'],
            'recognition_method' => ['nullable', 'string', 'in:straight_line,milestone,usage'],
            'deferred_revenue_account_id' => ['nullable', 'uuid'],
            'revenue_account_id' => ['nullable', 'uuid'],
            'notes' => ['nullable', 'string'],
        ]);

        $contract = $this->revenueService->createContract($org, $validated, $request->user());

        return response()->json([
            'data' => $contract,
            'meta' => ['message' => 'Revenue contract created and schedules generated.'],
            'errors' => [],
        ], 201);
    }

    /**
     * Show a contract with its recognition schedules.
     * GET /api/v1/organizations/{orgId}/revenue-contracts/{contractId}
     */
    public function showContract(Request $request, string $orgId, string $contractId): JsonResponse
    {
        $org = $this->getAuthorizedOrganization($request, $orgId);
        if (! $org) return $this->unauthorized();

        $contract = RevenueContract::withoutGlobalScopes()
            ->where('organization_id', $org->id)
            ->with(['schedules.journalEntry', 'customer', 'deferredRevenueAccount', 'revenueAccount'])
            ->findOrFail($contractId);

        return response()->json([
            'data' => $contract,
            'meta' => [
                'total_recognized' => $contract->totalRecognized(),
                'total_remaining' => $contract->totalRemaining(),
            ],
            'errors' => [],
        ]);
    }

    /**
     * Recognize a scheduled revenue amortization line.
     * POST /api/v1/organizations/{orgId}/revenue-schedules/{scheduleId}/recognize
     */
    public function recognizeSchedule(Request $request, string $orgId, string $scheduleId): JsonResponse
    {
        $org = $this->getAuthorizedOrganization($request, $orgId);
        if (! $org) return $this->unauthorized();

        $schedule = RevenueSchedule::withoutGlobalScopes()
            ->where('organization_id', $org->id)
            ->with('contract')
            ->findOrFail($scheduleId);

        $postedSchedule = $this->revenueService->recognizeSchedule($schedule, $request->user());

        return response()->json([
            'data' => $postedSchedule,
            'meta' => ['message' => 'Revenue schedule recognized and posted to General Ledger.'],
            'errors' => [],
        ]);
    }

    /**
     * Amend contract total value or end date.
     * POST /api/v1/organizations/{orgId}/revenue-contracts/{contractId}/amend
     */
    public function amendContract(Request $request, string $orgId, string $contractId): JsonResponse
    {
        $org = $this->getAuthorizedOrganization($request, $orgId);
        if (! $org) return $this->unauthorized();

        $contract = RevenueContract::withoutGlobalScopes()
            ->where('organization_id', $org->id)
            ->findOrFail($contractId);

        $validated = $request->validate([
            'total_contract_value' => ['required', 'numeric', 'min:0.01'],
            'end_date' => ['nullable', 'date'],
        ]);

        $updated = $this->revenueService->amendContract(
            $contract,
            (float) $validated['total_contract_value'],
            $validated['end_date'] ?? null,
            $request->user()
        );

        return response()->json([
            'data' => $updated,
            'meta' => ['message' => 'Revenue contract amended and pending schedules recalculated.'],
            'errors' => [],
        ]);
    }
}
