<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Accounting\Period\Models\AccountingPeriod;
use App\Domain\Consolidation\Models\ExchangeRate;
use App\Domain\Consolidation\Models\IntercompanyTransaction;
use App\Domain\Consolidation\Services\ConsolidationService;
use App\Domain\Consolidation\Services\CurrencyService;
use App\Domain\Organization\Models\Entity;
use App\Domain\Organization\Models\Organization;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class ConsolidationController extends Controller
{
    public function __construct(
        private readonly CurrencyService $currencyService,
        private readonly ConsolidationService $consolidationService
    ) {
    }

    private function getAuthorizedOrganization(Request $request, string $orgId): ?Organization
    {
        return $request->user()?->organizations()
            ->where('organizations.id', $orgId)
            ->first();
    }

    private function canManageConsolidation(Request $request, Organization $organization): bool
    {
        $user = $request->user();
        $role = $user->roleInOrganization($organization);

        if (in_array($role, ['owner', 'admin', 'finance_manager'])) {
            return true;
        }

        return $user->hasPermissionInOrganization('accounting.journal.post', $organization);
    }

    /**
     * List all legal entities and subsidiaries.
     */
    public function indexEntities(Request $request, string $orgId): JsonResponse
    {
        $organization = $this->getAuthorizedOrganization($request, $orgId);
        if (! $organization) return response()->json(['data' => null, 'meta' => [], 'errors' => [['code' => 'NOT_FOUND', 'message' => 'Organization not found']]], 404);

        $entities = Entity::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->with('branches')
            ->get();

        return response()->json([
            'data' => $entities,
            'meta' => ['total' => $entities->count(), 'timestamp' => now()->toIso8601String()],
            'errors' => [],
        ]);
    }

    /**
     * Create a legal entity or subsidiary.
     */
    public function storeEntity(Request $request, string $orgId): JsonResponse
    {
        $organization = $this->getAuthorizedOrganization($request, $orgId);
        if (! $organization) return response()->json(['data' => null, 'meta' => [], 'errors' => [['code' => 'NOT_FOUND', 'message' => 'Organization not found']]], 404);

        if (! $this->canManageConsolidation($request, $organization)) {
            return response()->json(['data' => null, 'meta' => [], 'errors' => [['code' => 'FORBIDDEN', 'message' => 'Permission denied']]], 403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'code' => ['required', 'string', 'max:20'],
            'currency' => ['nullable', 'string', 'size:3'],
            'is_primary' => ['nullable', 'boolean'],
        ]);

        $entity = Entity::create([
            'organization_id' => $organization->id,
            'name' => $validated['name'],
            'code' => strtoupper($validated['code']),
            'currency' => strtoupper($validated['currency'] ?? 'PKR'),
            'is_primary' => $validated['is_primary'] ?? false,
            'status' => 'active',
        ]);

        return response()->json([
            'data' => $entity,
            'meta' => ['message' => "Entity {$entity->name} ({$entity->code}) created successfully.", 'timestamp' => now()->toIso8601String()],
            'errors' => [],
        ], 201);
    }

    /**
     * List registered foreign exchange rates.
     */
    public function indexRates(Request $request, string $orgId): JsonResponse
    {
        $organization = $this->getAuthorizedOrganization($request, $orgId);
        if (! $organization) return response()->json(['data' => null, 'meta' => [], 'errors' => [['code' => 'NOT_FOUND', 'message' => 'Organization not found']]], 404);

        $rates = ExchangeRate::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->orderBy('effective_date', 'desc')
            ->get();

        return response()->json([
            'data' => $rates,
            'meta' => ['total' => $rates->count(), 'timestamp' => now()->toIso8601String()],
            'errors' => [],
        ]);
    }

    /**
     * Store or update an exchange rate.
     */
    public function storeRate(Request $request, string $orgId): JsonResponse
    {
        $organization = $this->getAuthorizedOrganization($request, $orgId);
        if (! $organization) return response()->json(['data' => null, 'meta' => [], 'errors' => [['code' => 'NOT_FOUND', 'message' => 'Organization not found']]], 404);

        if (! $this->canManageConsolidation($request, $organization)) {
            return response()->json(['data' => null, 'meta' => [], 'errors' => [['code' => 'FORBIDDEN', 'message' => 'Permission denied']]], 403);
        }

        $validated = $request->validate([
            'from_currency' => ['required', 'string', 'size:3'],
            'to_currency' => ['required', 'string', 'size:3'],
            'rate' => ['required', 'numeric', 'gt:0'],
            'effective_date' => ['required', 'date'],
            'source' => ['nullable', 'string', 'max:50'],
        ]);

        $rate = $this->currencyService->setExchangeRate(
            $organization,
            $validated['from_currency'],
            $validated['to_currency'],
            (float) $validated['rate'],
            $validated['effective_date'],
            $validated['source'] ?? 'manual'
        );

        return response()->json([
            'data' => $rate,
            'meta' => ['message' => "Exchange rate for {$rate->from_currency}/{$rate->to_currency} updated to {$rate->rate}.", 'timestamp' => now()->toIso8601String()],
            'errors' => [],
        ], 201);
    }

    /**
     * Run month-end currency revaluation routine.
     */
    public function runRevaluation(Request $request, string $orgId): JsonResponse
    {
        $organization = $this->getAuthorizedOrganization($request, $orgId);
        if (! $organization) return response()->json(['data' => null, 'meta' => [], 'errors' => [['code' => 'NOT_FOUND', 'message' => 'Organization not found']]], 404);

        if (! $this->canManageConsolidation($request, $organization)) {
            return response()->json(['data' => null, 'meta' => [], 'errors' => [['code' => 'FORBIDDEN', 'message' => 'Permission denied']]], 403);
        }

        $validated = $request->validate([
            'accounting_period_id' => ['required', 'uuid'],
            'spot_rate_usd' => ['nullable', 'numeric', 'gt:0'],
        ]);

        $period = AccountingPeriod::withoutGlobalScopes()->where('organization_id', $organization->id)->findOrFail($validated['accounting_period_id']);
        $spotRate = (float) ($validated['spot_rate_usd'] ?? 280.00);

        try {
            $result = $this->currencyService->runCurrencyRevaluation($organization, $period, $request->user(), $spotRate);

            return response()->json([
                'data' => $result,
                'meta' => ['timestamp' => now()->toIso8601String()],
                'errors' => [],
            ]);
        } catch (Throwable $e) {
            return response()->json(['data' => null, 'meta' => [], 'errors' => [['code' => 'REVALUATION_FAILED', 'message' => $e->getMessage()]]], 422);
        }
    }

    /**
     * List intercompany transactions.
     */
    public function indexIntercompany(Request $request, string $orgId): JsonResponse
    {
        $organization = $this->getAuthorizedOrganization($request, $orgId);
        if (! $organization) return response()->json(['data' => null, 'meta' => [], 'errors' => [['code' => 'NOT_FOUND', 'message' => 'Organization not found']]], 404);

        $txs = IntercompanyTransaction::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->with(['fromEntity', 'toEntity'])
            ->orderBy('transaction_date', 'desc')
            ->get();

        return response()->json([
            'data' => $txs,
            'meta' => ['total' => $txs->count(), 'timestamp' => now()->toIso8601String()],
            'errors' => [],
        ]);
    }

    /**
     * Create an intercompany transaction.
     */
    public function storeIntercompany(Request $request, string $orgId): JsonResponse
    {
        $organization = $this->getAuthorizedOrganization($request, $orgId);
        if (! $organization) return response()->json(['data' => null, 'meta' => [], 'errors' => [['code' => 'NOT_FOUND', 'message' => 'Organization not found']]], 404);

        if (! $this->canManageConsolidation($request, $organization)) {
            return response()->json(['data' => null, 'meta' => [], 'errors' => [['code' => 'FORBIDDEN', 'message' => 'Permission denied']]], 403);
        }

        $validated = $request->validate([
            'from_entity_id' => ['required', 'uuid'],
            'to_entity_id' => ['required', 'uuid'],
            'transaction_date' => ['required', 'date'],
            'currency' => ['nullable', 'string', 'size:3'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'exchange_rate' => ['nullable', 'numeric', 'gt:0'],
            'description' => ['required', 'string', 'max:500'],
        ]);

        try {
            $tx = $this->consolidationService->createIntercompanyTransaction($organization, $validated, $request->user());

            return response()->json([
                'data' => $tx->load(['fromEntity', 'toEntity']),
                'meta' => ['message' => "Intercompany transaction {$tx->transaction_number} created.", 'timestamp' => now()->toIso8601String()],
                'errors' => [],
            ], 201);
        } catch (Throwable $e) {
            return response()->json(['data' => null, 'meta' => [], 'errors' => [['code' => 'INTERCOMPANY_CREATE_FAILED', 'message' => $e->getMessage()]]], 422);
        }
    }

    /**
     * Post intercompany transaction to the general ledger of both entities.
     */
    public function postIntercompany(Request $request, string $orgId, string $id): JsonResponse
    {
        $organization = $this->getAuthorizedOrganization($request, $orgId);
        if (! $organization) return response()->json(['data' => null, 'meta' => [], 'errors' => [['code' => 'NOT_FOUND', 'message' => 'Organization not found']]], 404);

        if (! $this->canManageConsolidation($request, $organization)) {
            return response()->json(['data' => null, 'meta' => [], 'errors' => [['code' => 'FORBIDDEN', 'message' => 'Permission denied']]], 403);
        }

        $tx = IntercompanyTransaction::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->findOrFail($id);

        try {
            $posted = $this->consolidationService->postIntercompanyTransaction($tx, $request->user());

            return response()->json([
                'data' => $posted,
                'meta' => ['message' => "Intercompany transaction {$posted->transaction_number} posted to both entity ledgers.", 'timestamp' => now()->toIso8601String()],
                'errors' => [],
            ]);
        } catch (Throwable $e) {
            return response()->json(['data' => null, 'meta' => [], 'errors' => [['code' => 'INTERCOMPANY_POST_FAILED', 'message' => $e->getMessage()]]], 422);
        }
    }

    /**
     * Run period intercompany eliminations.
     */
    public function eliminate(Request $request, string $orgId): JsonResponse
    {
        $organization = $this->getAuthorizedOrganization($request, $orgId);
        if (! $organization) return response()->json(['data' => null, 'meta' => [], 'errors' => [['code' => 'NOT_FOUND', 'message' => 'Organization not found']]], 404);

        if (! $this->canManageConsolidation($request, $organization)) {
            return response()->json(['data' => null, 'meta' => [], 'errors' => [['code' => 'FORBIDDEN', 'message' => 'Permission denied']]], 403);
        }

        $validated = $request->validate([
            'accounting_period_id' => ['required', 'uuid'],
        ]);

        $period = AccountingPeriod::withoutGlobalScopes()->where('organization_id', $organization->id)->findOrFail($validated['accounting_period_id']);

        try {
            $result = $this->consolidationService->eliminateIntercompany($organization, $period, $request->user());

            return response()->json([
                'data' => $result,
                'meta' => ['timestamp' => now()->toIso8601String()],
                'errors' => [],
            ]);
        } catch (Throwable $e) {
            return response()->json(['data' => null, 'meta' => [], 'errors' => [['code' => 'ELIMINATION_FAILED', 'message' => $e->getMessage()]]], 422);
        }
    }

    /**
     * Fetch consolidated financial reports with entity breakdowns and eliminations.
     */
    public function report(Request $request, string $orgId, string $reportType): JsonResponse
    {
        $organization = $this->getAuthorizedOrganization($request, $orgId);
        if (! $organization) return response()->json(['data' => null, 'meta' => [], 'errors' => [['code' => 'NOT_FOUND', 'message' => 'Organization not found']]], 404);

        $periodId = $request->query('accounting_period_id');
        $period = $periodId
            ? AccountingPeriod::withoutGlobalScopes()->where('organization_id', $organization->id)->findOrFail($periodId)
            : AccountingPeriod::withoutGlobalScopes()->where('organization_id', $organization->id)->orderBy('start_date', 'desc')->firstOrFail();

        $report = $this->consolidationService->getConsolidatedReport($organization, $reportType, $period);

        return response()->json([
            'data' => $report,
            'meta' => [
                'organization_id' => $organization->id,
                'timestamp' => now()->toIso8601String(),
            ],
            'errors' => [],
        ]);
    }
}
