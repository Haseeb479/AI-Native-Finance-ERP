<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Organization\Models\Organization;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ControlCenterOrganizationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $organizations = Organization::query()
            ->select(['id', 'name', 'status', 'created_at'])
            ->withCount('users')
            ->with(['users' => fn ($query) => $query
                ->select(['users.id', 'users.name', 'users.email'])
                ->wherePivot('role', 'owner')])
            ->orderByDesc('created_at')
            ->paginate(min(max($request->integer('per_page', 25), 1), 100));

        return response()->json([
            'data' => [
                'organizations' => $organizations->getCollection()
                    ->map(fn (Organization $organization): array => $this->summary($organization))
                    ->values(),
            ],
            'meta' => [
                'current_page' => $organizations->currentPage(),
                'last_page' => $organizations->lastPage(),
                'per_page' => $organizations->perPage(),
                'total' => $organizations->total(),
            ],
            'errors' => [],
        ]);
    }

    public function show(string $organizationId): JsonResponse
    {
        $organization = Organization::query()
            ->select(['id', 'name', 'status', 'created_at'])
            ->withCount('users')
            ->with(['users' => fn ($query) => $query
                ->select(['users.id', 'users.name', 'users.email'])
                ->wherePivot('role', 'owner')])
            ->findOrFail($organizationId);

        return response()->json([
            'data' => ['organization' => $this->summary($organization)],
            'meta' => ['timestamp' => now()->toIso8601String()],
            'errors' => [],
        ]);
    }

    private function summary(Organization $organization): array
    {
        $subscription = DB::table('subscriptions')
            ->join('plans', 'plans.id', '=', 'subscriptions.plan_id')
            ->where('subscriptions.organization_id', $organization->id)
            ->orderByDesc('subscriptions.created_at')
            ->first([
                'subscriptions.status',
                'subscriptions.current_period_end',
                'plans.name as plan_name',
            ]);

        $allowedMetrics = ['transactions_count', 'ai_queries', 'documents_uploaded', 'storage_bytes'];
        $usage = DB::table('usage_meters')
            ->where('organization_id', $organization->id)
            ->where('billing_period', now()->format('Y-m'))
            ->whereIn('metric_name', $allowedMetrics)
            ->orderBy('metric_name')
            ->get(['metric_name', 'usage_count', 'billing_period'])
            ->map(fn ($meter): array => [
                'metric' => $meter->metric_name,
                'count' => (int) $meter->usage_count,
                'period' => $meter->billing_period,
            ])
            ->all();

        return [
            'id' => $organization->id,
            'name' => $organization->name,
            'status' => $organization->status,
            'created_at' => $organization->created_at?->toIso8601String(),
            'owner' => $organization->users->map(fn ($user): array => [
                'name' => $user->name,
                'email' => $user->email,
            ])->first(),
            'members_count' => (int) $organization->users_count,
            'onboarding' => [
                'primary_entity_configured' => $organization->entities()->where('is_primary', true)->exists(),
                'branch_configured' => $organization->branches()->exists(),
            ],
            'subscription' => $subscription ? [
                'status' => $subscription->status,
                'plan_name' => $subscription->plan_name,
                'current_period_end' => $subscription->current_period_end,
            ] : null,
            'usage' => $usage,
        ];
    }
}
