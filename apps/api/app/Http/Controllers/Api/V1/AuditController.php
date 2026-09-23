<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\Services\AuditService;
use App\Domain\Organization\Models\Organization;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditController extends Controller
{
    public function __construct(private readonly AuditService $auditService)
    {
    }

    private function getAuthorizedOrganization(Request $request, string $orgId): ?Organization
    {
        return $request->user()?->organizations()
            ->where('organizations.id', $orgId)
            ->first();
    }

    private function canViewAuditLogs(Request $request, Organization $organization): bool
    {
        $user = $request->user();
        $role = $user->roleInOrganization($organization);

        if (in_array($role, ['owner', 'admin', 'auditor', 'finance_manager'])) {
            return true;
        }

        return $user->hasPermissionInOrganization('audit.view', $organization);
    }

    /**
     * List paginated audit events with filtering.
     */
    public function index(Request $request, string $orgId): JsonResponse
    {
        $organization = $this->getAuthorizedOrganization($request, $orgId);

        if (! $organization) {
            return response()->json([
                'data' => null,
                'meta' => ['timestamp' => now()->toIso8601String()],
                'errors' => [
                    [
                        'code' => 'ORGANIZATION_NOT_FOUND',
                        'message' => 'Organization not found or access denied.',
                    ],
                ],
            ], 404);
        }

        if (! $this->canViewAuditLogs($request, $organization)) {
            return response()->json([
                'data' => null,
                'meta' => ['timestamp' => now()->toIso8601String()],
                'errors' => [
                    [
                        'code' => 'FORBIDDEN',
                        'message' => 'You do not have permission to view audit logs.',
                    ],
                ],
            ], 403);
        }

        $filters = $request->only(['event', 'auditable_type', 'auditable_id', 'user_id', 'date_from', 'date_to']);
        $perPage = min((int) $request->input('per_page', 25), 100);

        $paginator = $this->auditService->getLogs($organization, $filters, $perPage);

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'organization_id' => $organization->id,
                'timestamp' => now()->toIso8601String(),
            ],
            'errors' => [],
        ]);
    }

    /**
     * Export all matching audit events for compliance reporting.
     */
    public function export(Request $request, string $orgId): JsonResponse
    {
        $organization = $this->getAuthorizedOrganization($request, $orgId);

        if (! $organization) {
            return response()->json([
                'data' => null,
                'meta' => ['timestamp' => now()->toIso8601String()],
                'errors' => [
                    [
                        'code' => 'ORGANIZATION_NOT_FOUND',
                        'message' => 'Organization not found or access denied.',
                    ],
                ],
            ], 404);
        }

        if (! $this->canViewAuditLogs($request, $organization)) {
            return response()->json([
                'data' => null,
                'meta' => ['timestamp' => now()->toIso8601String()],
                'errors' => [
                    [
                        'code' => 'FORBIDDEN',
                        'message' => 'You do not have permission to export audit logs.',
                    ],
                ],
            ], 403);
        }

        $filters = $request->only(['event', 'auditable_type', 'date_from', 'date_to']);
        $exportData = $this->auditService->exportLogs($organization, $filters);

        return response()->json([
            'data' => $exportData,
            'meta' => [
                'organization_id' => $organization->id,
                'timestamp' => now()->toIso8601String(),
            ],
            'errors' => [],
        ]);
    }

    /**
     * Verify audit log immutability and trail integrity.
     */
    public function verify(Request $request, string $orgId): JsonResponse
    {
        $organization = $this->getAuthorizedOrganization($request, $orgId);

        if (! $organization) {
            return response()->json([
                'data' => null,
                'meta' => ['timestamp' => now()->toIso8601String()],
                'errors' => [
                    [
                        'code' => 'ORGANIZATION_NOT_FOUND',
                        'message' => 'Organization not found or access denied.',
                    ],
                ],
            ], 404);
        }

        if (! $this->canViewAuditLogs($request, $organization)) {
            return response()->json([
                'data' => null,
                'meta' => ['timestamp' => now()->toIso8601String()],
                'errors' => [
                    [
                        'code' => 'FORBIDDEN',
                        'message' => 'You do not have permission to verify audit trails.',
                    ],
                ],
            ], 403);
        }

        $verification = $this->auditService->verifyAuditTrailIntegrity($organization);

        return response()->json([
            'data' => $verification,
            'meta' => [
                'timestamp' => now()->toIso8601String(),
            ],
            'errors' => [],
        ]);
    }
}
