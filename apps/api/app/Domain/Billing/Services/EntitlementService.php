<?php

namespace App\Domain\Billing\Services;

use App\Domain\Billing\Models\Plan;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Billing\Models\UsageMeter;
use App\Domain\Organization\Models\Organization;
use Illuminate\Auth\Access\AuthorizationException;

class EntitlementService
{
    /**
     * Get or create active subscription and plan for an organization (P2-28, P2-30).
     */
    public function getOrganizationPlan(Organization|string $organization): Plan
    {
        $orgId = is_string($organization) ? $organization : $organization->id;

        $this->ensureDefaultPlansExist();

        $subscription = Subscription::with('plan')
            ->where('organization_id', $orgId)
            ->whereIn('status', ['active', 'trialing'])
            ->first();

        if ($subscription && $subscription->plan) {
            return $subscription->plan;
        }

        // Default fallback to starter tier
        return Plan::firstOrCreate(
            ['id' => 'starter'],
            [
                'name' => 'Starter Plan',
                'price_monthly' => '0.0000',
                'currency' => 'PKR',
                'max_seats' => 5,
                'monthly_transaction_limit' => 500,
                'monthly_ai_query_limit' => 200,
                'max_storage_mb' => 1024,
                'features' => ['gl', 'invoicing', 'reconciliation', 'copilot'],
                'is_active' => true,
            ]
        );
    }

    /**
     * Increment and record usage metric for current billing period (P2-29).
     */
    public function recordUsage(Organization|string $organization, string $metricName, int $quantity = 1): int
    {
        $orgId = is_string($organization) ? $organization : $organization->id;
        $period = now()->format('Y-m');

        $meter = UsageMeter::firstOrCreate(
            [
                'organization_id' => $orgId,
                'metric_name' => $metricName,
                'billing_period' => $period,
            ],
            ['usage_count' => 0]
        );

        $meter->increment('usage_count', $quantity);

        return (int) $meter->usage_count;
    }

    /**
     * Get current month usage count.
     */
    public function getUsage(Organization|string $organization, string $metricName): int
    {
        $orgId = is_string($organization) ? $organization : $organization->id;
        $period = now()->format('Y-m');

        return (int) UsageMeter::where('organization_id', $orgId)
            ->where('metric_name', $metricName)
            ->where('billing_period', $period)
            ->value('usage_count') ?? 0;
    }

    /**
     * Enforce strict server-side entitlement check (P2-30).
     */
    public function assertEntitled(Organization|string $organization, string $metricName, int $requestedQuantity = 1): void
    {
        $plan = $this->getOrganizationPlan($organization);
        $currentUsage = $this->getUsage($organization, $metricName);

        $limit = match ($metricName) {
            'transactions' => $plan->monthly_transaction_limit,
            'ai_queries' => $plan->monthly_ai_query_limit,
            default => PHP_INT_MAX,
        };

        if (($currentUsage + $requestedQuantity) > $limit) {
            throw new AuthorizationException(
                "Plan limit exceeded: Your '{$plan->name}' plan allows up to {$limit} {$metricName} per month. Current usage: {$currentUsage}."
            );
        }
    }

    /**
     * Seed baseline tier plans if missing.
     */
    public function ensureDefaultPlansExist(): void
    {
        $plans = [
            'starter' => [
                'name' => 'Starter Plan',
                'price_monthly' => '0.0000',
                'max_seats' => 5,
                'monthly_transaction_limit' => 500,
                'monthly_ai_query_limit' => 200,
                'max_storage_mb' => 1024,
                'features' => ['gl', 'invoicing', 'reconciliation', 'copilot'],
            ],
            'growth' => [
                'name' => 'Growth Scale',
                'price_monthly' => '15000.0000',
                'max_seats' => 25,
                'monthly_transaction_limit' => 5000,
                'monthly_ai_query_limit' => 2500,
                'max_storage_mb' => 10240,
                'features' => ['gl', 'invoicing', 'bills', 'reconciliation', 'inventory', 'copilot', 'fbr'],
            ],
            'enterprise' => [
                'name' => 'Enterprise Sovereign',
                'price_monthly' => '75000.0000',
                'max_seats' => 100,
                'monthly_transaction_limit' => 50000,
                'monthly_ai_query_limit' => 25000,
                'max_storage_mb' => 102400,
                'features' => ['*'],
            ],
        ];

        foreach ($plans as $id => $data) {
            Plan::firstOrCreate(['id' => $id], array_merge($data, [
                'currency' => 'PKR',
                'is_active' => true,
            ]));
        }
    }
}
