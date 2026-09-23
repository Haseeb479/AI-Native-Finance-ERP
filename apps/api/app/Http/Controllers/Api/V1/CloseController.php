<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Accounting\Period\Models\AccountingPeriod;
use App\Domain\Close\Models\CloseTask;
use App\Domain\Close\Models\FixedAsset;
use App\Domain\Close\Services\CloseManager;
use App\Domain\Organization\Models\Organization;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class CloseController extends Controller
{
    public function __construct(private readonly CloseManager $closeManager)
    {
    }

    private function getAuthorizedOrganization(Request $request, string $orgId): ?Organization
    {
        return $request->user()?->organizations()
            ->where('organizations.id', $orgId)
            ->first();
    }

    private function canManageClose(Request $request, Organization $organization): bool
    {
        $user = $request->user();
        $role = $user->roleInOrganization($organization);

        if (in_array($role, ['owner', 'admin', 'accountant', 'finance_manager'])) {
            return true;
        }

        return $user->hasPermissionInOrganization('accounting.journal.post', $organization);
    }

    /**
     * Get or initialize close cycle and checklist tasks for a period.
     */
    public function showCycle(Request $request, string $orgId, string $periodId): JsonResponse
    {
        $organization = $this->getAuthorizedOrganization($request, $orgId);
        if (! $organization) return response()->json(['data' => null, 'meta' => [], 'errors' => [['code' => 'NOT_FOUND', 'message' => 'Organization not found']]], 404);

        $period = AccountingPeriod::withoutGlobalScopes()->where('organization_id', $organization->id)->findOrFail($periodId);
        $cycle = $this->closeManager->getOrCreateCloseCycle($organization, $period);

        return response()->json([
            'data' => $cycle,
            'meta' => [
                'organization_id' => $organization->id,
                'period' => $period->name,
                'progress_percent' => (float) $cycle->progress_percent,
                'timestamp' => now()->toIso8601String(),
            ],
            'errors' => [],
        ]);
    }

    /**
     * Toggle a close task completion status.
     */
    public function toggleTask(Request $request, string $orgId, string $periodId, string $taskId): JsonResponse
    {
        $organization = $this->getAuthorizedOrganization($request, $orgId);
        if (! $organization) return response()->json(['data' => null, 'meta' => [], 'errors' => [['code' => 'NOT_FOUND', 'message' => 'Organization not found']]], 404);

        if (! $this->canManageClose($request, $organization)) {
            return response()->json(['data' => null, 'meta' => [], 'errors' => [['code' => 'FORBIDDEN', 'message' => 'Permission denied']]], 403);
        }

        $task = CloseTask::withoutGlobalScopes()->where('organization_id', $organization->id)->findOrFail($taskId);
        $completed = $request->has('completed') ? $request->boolean('completed') : null;

        $updated = $this->closeManager->toggleTask($task, $request->user(), $completed);

        return response()->json([
            'data' => $updated,
            'meta' => [
                'message' => "Task '{$updated->title}' status updated.",
                'cycle_progress' => (float) $updated->cycle->progress_percent,
                'timestamp' => now()->toIso8601String(),
            ],
            'errors' => [],
        ]);
    }

    /**
     * Run monthly asset depreciation routine for the period.
     */
    public function runDepreciation(Request $request, string $orgId, string $periodId): JsonResponse
    {
        $organization = $this->getAuthorizedOrganization($request, $orgId);
        if (! $organization) return response()->json(['data' => null, 'meta' => [], 'errors' => [['code' => 'NOT_FOUND', 'message' => 'Organization not found']]], 404);

        if (! $this->canManageClose($request, $organization)) {
            return response()->json(['data' => null, 'meta' => [], 'errors' => [['code' => 'FORBIDDEN', 'message' => 'Permission denied']]], 403);
        }

        $period = AccountingPeriod::withoutGlobalScopes()->where('organization_id', $organization->id)->findOrFail($periodId);

        try {
            $result = $this->closeManager->runDepreciation($organization, $period, $request->user());

            return response()->json([
                'data' => $result,
                'meta' => [
                    'message' => $result['depreciation_posted'] 
                        ? "Depreciation of PKR {$result['total_amount']} posted to General Ledger."
                        : $result['message'],
                    'timestamp' => now()->toIso8601String(),
                ],
                'errors' => [],
            ]);
        } catch (Throwable $e) {
            return response()->json(['data' => null, 'meta' => [], 'errors' => [['code' => 'DEPRECIATION_FAILED', 'message' => $e->getMessage()]]], 422);
        }
    }

    /**
     * Run and view period-over-period balance flux analysis.
     */
    public function fluxAnalysis(Request $request, string $orgId, string $periodId): JsonResponse
    {
        $organization = $this->getAuthorizedOrganization($request, $orgId);
        if (! $organization) return response()->json(['data' => null, 'meta' => [], 'errors' => [['code' => 'NOT_FOUND', 'message' => 'Organization not found']]], 404);

        $period = AccountingPeriod::withoutGlobalScopes()->where('organization_id', $organization->id)->findOrFail($periodId);
        $priorPeriod = $request->filled('prior_period_id')
            ? AccountingPeriod::withoutGlobalScopes()->where('organization_id', $organization->id)->findOrFail($request->query('prior_period_id'))
            : null;

        $flux = $this->closeManager->runFluxAnalysis($organization, $period, $priorPeriod);

        return response()->json([
            'data' => $flux,
            'meta' => [
                'organization_id' => $organization->id,
                'timestamp' => now()->toIso8601String(),
            ],
            'errors' => [],
        ]);
    }

    /**
     * Verify pre-close readiness and detect potential blockers.
     */
    public function readiness(Request $request, string $orgId, string $periodId): JsonResponse
    {
        $organization = $this->getAuthorizedOrganization($request, $orgId);
        if (! $organization) return response()->json(['data' => null, 'meta' => [], 'errors' => [['code' => 'NOT_FOUND', 'message' => 'Organization not found']]], 404);

        $period = AccountingPeriod::withoutGlobalScopes()->where('organization_id', $organization->id)->findOrFail($periodId);
        $readiness = $this->closeManager->verifyCloseReadiness($organization, $period);

        return response()->json([
            'data' => $readiness,
            'meta' => [
                'organization_id' => $organization->id,
                'timestamp' => now()->toIso8601String(),
            ],
            'errors' => [],
        ]);
    }

    /**
     * List registered fixed assets.
     */
    public function indexFixedAssets(Request $request, string $orgId): JsonResponse
    {
        $organization = $this->getAuthorizedOrganization($request, $orgId);
        if (! $organization) return response()->json(['data' => null, 'meta' => [], 'errors' => [['code' => 'NOT_FOUND', 'message' => 'Organization not found']]], 404);

        $assets = FixedAsset::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->with(['assetAccount', 'accumulatedDepreciationAccount', 'depreciationExpenseAccount'])
            ->get();

        return response()->json([
            'data' => $assets,
            'meta' => ['total' => $assets->count(), 'timestamp' => now()->toIso8601String()],
            'errors' => [],
        ]);
    }

    /**
     * Register a new fixed asset.
     */
    public function storeFixedAsset(Request $request, string $orgId): JsonResponse
    {
        $organization = $this->getAuthorizedOrganization($request, $orgId);
        if (! $organization) return response()->json(['data' => null, 'meta' => [], 'errors' => [['code' => 'NOT_FOUND', 'message' => 'Organization not found']]], 404);

        if (! $this->canManageClose($request, $organization)) {
            return response()->json(['data' => null, 'meta' => [], 'errors' => [['code' => 'FORBIDDEN', 'message' => 'Permission denied']]], 403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'asset_number' => ['nullable', 'string', 'max:50'],
            'asset_account_id' => ['required', 'uuid'],
            'accumulated_depreciation_account_id' => ['required', 'uuid'],
            'depreciation_expense_account_id' => ['required', 'uuid'],
            'purchase_date' => ['required', 'date'],
            'purchase_cost' => ['required', 'numeric', 'gt:0'],
            'salvage_value' => ['nullable', 'numeric', 'min:0'],
            'useful_life_months' => ['required', 'integer', 'min:1'],
        ]);

        try {
            $asset = $this->closeManager->createFixedAsset($organization, $validated);

            return response()->json([
                'data' => $asset,
                'meta' => ['message' => "Fixed asset '{$asset->name}' created successfully.", 'timestamp' => now()->toIso8601String()],
                'errors' => [],
            ], 201);
        } catch (Throwable $e) {
            return response()->json(['data' => null, 'meta' => [], 'errors' => [['code' => 'ASSET_CREATE_FAILED', 'message' => $e->getMessage()]]], 422);
        }
    }
}
