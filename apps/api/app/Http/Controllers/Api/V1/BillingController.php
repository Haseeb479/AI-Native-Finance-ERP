<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Billing\Services\EntitlementService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BillingController extends Controller
{
    public function __construct(
        protected EntitlementService $entitlementService
    ) {}

    /**
     * Get organization subscription, plan entitlements, and current usage metrics (P2-28, P2-29, P2-30).
     */
    public function show(Request $request, string $orgId): JsonResponse
    {
        $plan = $this->entitlementService->getOrganizationPlan($orgId);

        $transactionsUsed = $this->entitlementService->getUsage($orgId, 'transactions');
        $aiQueriesUsed = $this->entitlementService->getUsage($orgId, 'ai_queries');

        return response()->json([
            'data' => [
                'plan' => [
                    'id' => $plan->id,
                    'name' => $plan->name,
                    'price_monthly' => $plan->price_monthly,
                    'currency' => $plan->currency,
                    'max_seats' => $plan->max_seats,
                    'features' => $plan->features,
                ],
                'entitlements' => [
                    'monthly_transaction_limit' => $plan->monthly_transaction_limit,
                    'monthly_ai_query_limit' => $plan->monthly_ai_query_limit,
                    'max_storage_mb' => $plan->max_storage_mb,
                ],
                'current_usage' => [
                    'billing_period' => now()->format('Y-m'),
                    'transactions' => $transactionsUsed,
                    'ai_queries' => $aiQueriesUsed,
                ],
            ],
            'meta' => [
                'timestamp' => now()->toIso8601String(),
            ],
            'errors' => [],
        ], 200);
    }
}
