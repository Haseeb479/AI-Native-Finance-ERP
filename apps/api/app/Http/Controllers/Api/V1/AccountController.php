<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Accounting\ChartOfAccounts\Models\Account;
use App\Domain\Accounting\ChartOfAccounts\Models\AccountType;
use App\Domain\Accounting\ChartOfAccounts\Templates\PakistanSmeChartTemplate;
use App\Domain\Organization\Models\Organization;
use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\CreateAccountRequest;
use App\Http\Requests\Accounting\UpdateAccountRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountController extends Controller
{
    private function getAuthorizedOrganization(Request $request, string $orgId): ?Organization
    {
        return $request->user()?->organizations()
            ->where('organizations.id', $orgId)
            ->first();
    }

    /**
     * List all accounts for an organization.
     */
    public function index(Request $request, string $orgId): JsonResponse
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

        $query = Account::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->with(['type', 'group', 'parent']);

        if ($request->filled('classification')) {
            $query->where('classification', $request->query('classification'));
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', filter_var($request->query('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->filled('search')) {
            $search = $request->query('search');
            $query->where(function ($q) use ($search) {
                $q->where('code', 'ILIKE', "%{$search}%")
                  ->orWhere('name', 'ILIKE', "%{$search}%");
            });
        }

        if ($request->boolean('tree')) {
            $accounts = (clone $query)->whereNull('parent_account_id')
                ->with(['children.children', 'type', 'group'])
                ->orderBy('code')
                ->get();
        } else {
            $accounts = $query->orderBy('code')->get();
        }

        return response()->json([
            'data' => $accounts,
            'meta' => [
                'total' => $accounts->count(),
                'organization_id' => $organization->id,
                'timestamp' => now()->toISOString(),
            ],
            'errors' => [],
        ]);
    }

    /**
     * Create a new account in the organization.
     */
    public function store(CreateAccountRequest $request, string $orgId): JsonResponse
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

        $data = $request->validated();
        $data['organization_id'] = $organization->id;
        $data['currency'] = $data['currency'] ?? $organization->base_currency ?? 'PKR';

        $account = Account::withoutGlobalScopes()->create($data);
        $account->load(['type', 'group', 'parent']);

        return response()->json([
            'data' => $account,
            'meta' => [
                'message' => 'Account created successfully',
                'timestamp' => now()->toISOString(),
            ],
            'errors' => [],
        ], 201);
    }

    /**
     * Show account details.
     */
    public function show(Request $request, string $orgId, string $accountId): JsonResponse
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

        $account = Account::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->with(['type', 'group', 'parent', 'children'])
            ->findOrFail($accountId);

        return response()->json([
            'data' => $account,
            'meta' => [
                'timestamp' => now()->toISOString(),
            ],
            'errors' => [],
        ]);
    }

    /**
     * Update an account.
     */
    public function update(UpdateAccountRequest $request, string $orgId, string $accountId): JsonResponse
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

        $account = Account::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->findOrFail($accountId);

        $account->update($request->validated());
        $account->load(['type', 'group', 'parent']);

        return response()->json([
            'data' => $account,
            'meta' => [
                'message' => 'Account updated successfully',
                'timestamp' => now()->toISOString(),
            ],
            'errors' => [],
        ]);
    }

    /**
     * Archive or delete an account.
     */
    public function destroy(Request $request, string $orgId, string $accountId): JsonResponse
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

        $account = Account::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->findOrFail($accountId);

        if ($account->is_system) {
            return response()->json([
                'data' => null,
                'meta' => ['timestamp' => now()->toISOString()],
                'errors' => [
                    [
                        'code' => 'SYSTEM_ACCOUNT_PROTECTED',
                        'message' => 'System accounts cannot be archived or deleted.',
                    ],
                ],
            ], 422);
        }

        // Archive by setting is_active to false and soft deleting
        $account->update(['is_active' => false]);
        $account->delete();

        return response()->json([
            'data' => [
                'id' => $account->id,
                'is_active' => false,
                'archived' => true,
            ],
            'meta' => [
                'message' => 'Account archived successfully',
                'timestamp' => now()->toISOString(),
            ],
            'errors' => [],
        ]);
    }

    /**
     * Seed Pakistan SME Chart of Accounts for this organization.
     */
    public function seedTemplate(Request $request, string $orgId): JsonResponse
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

        $existingCount = Account::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->count();

        if ($existingCount > 0 && !$request->boolean('force')) {
            return response()->json([
                'data' => null,
                'meta' => ['timestamp' => now()->toISOString()],
                'errors' => [
                    [
                        'code' => 'CHART_ALREADY_EXISTS',
                        'message' => 'Organization already has accounts configured. Use force=true to override.',
                    ],
                ],
            ], 409);
        }

        $seededCount = PakistanSmeChartTemplate::seedForOrganization($organization);

        return response()->json([
            'data' => [
                'organization_id' => $organization->id,
                'accounts_seeded' => $seededCount,
                'template' => 'Pakistan SME Standard Chart',
            ],
            'meta' => [
                'message' => "Successfully seeded {$seededCount} accounts for {$organization->name}",
                'timestamp' => now()->toISOString(),
            ],
            'errors' => [],
        ], 201);
    }

    /**
     * List all standard account types and their groups.
     */
    public function types(): JsonResponse
    {
        $types = AccountType::with('groups')->get();

        return response()->json([
            'data' => $types,
            'meta' => [
                'timestamp' => now()->toISOString(),
            ],
            'errors' => [],
        ]);
    }
}
