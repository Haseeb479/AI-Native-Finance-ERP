<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\AI\Models\AiRunLog;
use App\Domain\AI\Services\AiGatewayService;
use App\Domain\Organization\Models\Organization;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiGatewayController extends Controller
{
    public function __construct(private readonly AiGatewayService $gatewayService)
    {
    }

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
     * Classify a bank or transaction record into Chart of Accounts.
     * POST /api/v1/organizations/{orgId}/ai/classify-transaction
     */
    public function classifyTransaction(Request $request, string $orgId): JsonResponse
    {
        $organization = $this->getAuthorizedOrganization($request, $orgId);
        if (! $organization) return $this->unauthorized();

        $validated = $request->validate([
            'description' => ['required', 'string', 'max:500'],
            'amount' => ['required', 'numeric'],
            'currency' => ['nullable', 'string', 'size:3'],
        ]);

        try {
            $result = $this->gatewayService->classifyTransaction(
                organization: $organization,
                user: $request->user(),
                description: $validated['description'],
                amount: (float) $validated['amount'],
                currency: $validated['currency'] ?? $organization->base_currency ?? 'PKR',
            );

            return response()->json([
                'data' => $result,
                'meta' => [
                    'organization_id' => $orgId,
                    'model' => 'gemini-1.5-flash',
                    'timestamp' => now()->toISOString(),
                ],
                'errors' => [],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'data' => null,
                'meta' => ['timestamp' => now()->toISOString()],
                'errors' => [
                    [
                        'code' => 'AI_EXECUTION_ERROR',
                        'message' => $e->getMessage(),
                    ],
                ],
            ], 502);
        }
    }

    /**
     * Get aggregate token, cost, and run metrics.
     * GET /api/v1/organizations/{orgId}/ai/usage-metrics
     */
    public function usageMetrics(Request $request, string $orgId): JsonResponse
    {
        $organization = $this->getAuthorizedOrganization($request, $orgId);
        if (! $organization) return $this->unauthorized();

        $metrics = $this->gatewayService->getUsageMetrics($organization);

        return response()->json([
            'data' => $metrics,
            'meta' => ['timestamp' => now()->toISOString()],
            'errors' => [],
        ]);
    }

    /**
     * List audit run logs for AI tasks.
     * GET /api/v1/organizations/{orgId}/ai/logs
     */
    public function runLogs(Request $request, string $orgId): JsonResponse
    {
        $organization = $this->getAuthorizedOrganization($request, $orgId);
        if (! $organization) return $this->unauthorized();

        $logs = AiRunLog::where('organization_id', $organization->id)
            ->with('user:id,name,email')
            ->latest()
            ->paginate(20);

        return response()->json([
            'data' => $logs->items(),
            'meta' => [
                'total' => $logs->total(),
                'page' => $logs->currentPage(),
                'per_page' => $logs->perPage(),
                'last_page' => $logs->lastPage(),
            ],
            'errors' => [],
        ]);
    }
}
