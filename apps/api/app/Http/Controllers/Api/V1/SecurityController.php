<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Organization\Models\Organization;
use App\Domain\Security\Models\SecurityApiKey;
use App\Domain\Security\Models\SecurityEvent;
use App\Domain\Security\Services\SecurityService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SecurityController extends Controller
{
    public function __construct(
        protected SecurityService $securityService
    ) {}

    /**
     * List organization API Keys.
     */
    public function indexApiKeys(Request $request, string $orgId): JsonResponse
    {
        $keys = SecurityApiKey::withoutGlobalScopes()
            ->where('organization_id', $orgId)
            ->with('creator')
            ->get();

        return response()->json([
            'data' => $keys,
            'meta' => ['total' => $keys->count()],
            'errors' => [],
        ]);
    }

    /**
     * Create API Key.
     */
    public function storeApiKey(Request $request, string $orgId): JsonResponse
    {
        $organization = Organization::findOrFail($orgId);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'rate_limit_per_minute' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'allowed_ips' => ['nullable', 'array'],
        ]);

        $result = $this->securityService->createApiKey(
            $organization,
            $validated['name'],
            $validated['allowed_ips'] ?? [],
            $validated['rate_limit_per_minute'] ?? 60,
            $request->user()
        );

        return response()->json([
            'data' => [
                'api_key' => $result['key'],
                'plain_key' => $result['plain_key'],
            ],
            'meta' => ['message' => 'API Key created. Copy your secret key now; it cannot be retrieved later.'],
            'errors' => [],
        ], 201);
    }

    /**
     * Rotate secret for an existing API Key.
     */
    public function rotateApiKey(Request $request, string $orgId, string $id): JsonResponse
    {
        $key = SecurityApiKey::withoutGlobalScopes()
            ->where('organization_id', $orgId)
            ->findOrFail($id);

        $result = $this->securityService->rotateApiKey($key, $request->user());

        return response()->json([
            'data' => [
                'api_key' => $result['key'],
                'new_plain_key' => $result['plain_key'],
            ],
            'meta' => ['message' => 'API Key rotated successfully. Old key is immediately revoked.'],
            'errors' => [],
        ]);
    }

    /**
     * Revoke API Key.
     */
    public function revokeApiKey(Request $request, string $orgId, string $id): JsonResponse
    {
        $key = SecurityApiKey::withoutGlobalScopes()
            ->where('organization_id', $orgId)
            ->findOrFail($id);

        $revoked = $this->securityService->revokeApiKey($key, $request->user());

        return response()->json([
            'data' => $revoked,
            'meta' => ['message' => 'API Key revoked.'],
            'errors' => [],
        ]);
    }

    /**
     * List security audit events.
     */
    public function indexEvents(Request $request, string $orgId): JsonResponse
    {
        $query = SecurityEvent::withoutGlobalScopes()
            ->where('organization_id', $orgId)
            ->with('user')
            ->latest();

        if ($request->filled('severity')) {
            $query->where('severity', $request->query('severity'));
        }

        if ($request->filled('event_type')) {
            $query->where('event_type', $request->query('event_type'));
        }

        $events = $query->paginate(25);

        return response()->json([
            'data' => $events->items(),
            'meta' => [
                'current_page' => $events->currentPage(),
                'total' => $events->total(),
            ],
            'errors' => [],
        ]);
    }

    /**
     * Test tenant isolation verification.
     */
    public function verifyTenantAccess(Request $request, string $orgId): JsonResponse
    {
        $user = $request->user();
        $isAuthorized = $this->securityService->enforceTenantIsolation(
            $user,
            $orgId,
            $request->ip(),
            $request->userAgent()
        );

        if (! $isAuthorized) {
            return response()->json([
                'data' => null,
                'meta' => ['authorized' => false],
                'errors' => [
                    ['code' => 'TENANT_ISOLATION_VIOLATION', 'message' => 'Access denied: Tenant boundary violation logged.'],
                ],
            ], 403);
        }

        return response()->json([
            'data' => [
                'user_id' => $user->id,
                'organization_id' => $orgId,
                'authorized' => true,
            ],
            'meta' => ['message' => 'Tenant isolation check passed.'],
            'errors' => [],
        ]);
    }
}
