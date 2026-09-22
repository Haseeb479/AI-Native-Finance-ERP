<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Organization\Models\Organization;
use App\Domain\Sales\Models\Customer;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\CreateCustomerRequest;
use App\Http\Requests\Sales\UpdateCustomerRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    private function getAuthorizedOrganization(Request $request, string $orgId): ?Organization
    {
        return $request->user()?->organizations()
            ->where('organizations.id', $orgId)
            ->first();
    }

    /**
     * List customers for an organization.
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

        $query = Customer::withoutGlobalScopes()
            ->where('organization_id', $organization->id);

        if ($request->filled('search')) {
            $search = $request->query('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ILIKE', "%{$search}%")
                  ->orWhere('legal_name', 'ILIKE', "%{$search}%")
                  ->orWhere('ntn', 'ILIKE', "%{$search}%");
            });
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', filter_var($request->query('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        $customers = $query->orderBy('name')->get();

        $data = $customers->map(function ($customer) {
            $arr = $customer->toArray();
            $arr['total_outstanding'] = $customer->totalOutstanding();
            return $arr;
        });

        return response()->json([
            'data' => $data,
            'meta' => [
                'total' => $customers->count(),
                'organization_id' => $organization->id,
                'timestamp' => now()->toISOString(),
            ],
            'errors' => [],
        ]);
    }

    /**
     * Create a new customer.
     */
    public function store(CreateCustomerRequest $request, string $orgId): JsonResponse
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

        $customer = Customer::withoutGlobalScopes()->create($data);

        return response()->json([
            'data' => $customer,
            'meta' => [
                'message' => 'Customer created successfully',
                'timestamp' => now()->toISOString(),
            ],
            'errors' => [],
        ], 201);
    }

    /**
     * View customer details with invoices.
     */
    public function show(Request $request, string $orgId, string $customerId): JsonResponse
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

        $customer = Customer::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->with(['invoices' => fn ($q) => $q->orderBy('issue_date', 'desc')])
            ->findOrFail($customerId);

        $data = $customer->toArray();
        $data['total_outstanding'] = $customer->totalOutstanding();

        return response()->json([
            'data' => $data,
            'meta' => [
                'timestamp' => now()->toISOString(),
            ],
            'errors' => [],
        ]);
    }

    /**
     * Update customer details.
     */
    public function update(UpdateCustomerRequest $request, string $orgId, string $customerId): JsonResponse
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

        $customer = Customer::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->findOrFail($customerId);

        $customer->update($request->validated());

        return response()->json([
            'data' => $customer,
            'meta' => [
                'message' => 'Customer updated successfully',
                'timestamp' => now()->toISOString(),
            ],
            'errors' => [],
        ]);
    }

    /**
     * Soft delete customer.
     */
    public function destroy(Request $request, string $orgId, string $customerId): JsonResponse
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

        $customer = Customer::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->findOrFail($customerId);

        $customer->delete();

        return response()->json([
            'data' => ['id' => $customer->id, 'deleted' => true],
            'meta' => [
                'message' => 'Customer deleted successfully',
                'timestamp' => now()->toISOString(),
            ],
            'errors' => [],
        ]);
    }
}
