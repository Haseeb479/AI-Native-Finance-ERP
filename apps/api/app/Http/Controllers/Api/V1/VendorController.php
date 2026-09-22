<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Organization\Models\Organization;
use App\Domain\Purchasing\Models\Vendor;
use App\Http\Controllers\Controller;
use App\Http\Requests\Purchasing\CreateVendorRequest;
use App\Http\Requests\Purchasing\UpdateVendorRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VendorController extends Controller
{
    private function getAuthorizedOrganization(Request $request, string $orgId): ?Organization
    {
        return $request->user()?->organizations()
            ->where('organizations.id', $orgId)
            ->first();
    }

    /**
     * List vendors for an organization.
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

        $query = Vendor::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->with('defaultExpenseAccount:id,code,name');

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

        $vendors = $query->orderBy('name')->get();

        $data = $vendors->map(function ($vendor) {
            $arr = $vendor->toArray();
            $arr['total_outstanding'] = $vendor->totalOutstanding();
            return $arr;
        });

        return response()->json([
            'data' => $data,
            'meta' => [
                'total' => $vendors->count(),
                'organization_id' => $organization->id,
                'timestamp' => now()->toISOString(),
            ],
            'errors' => [],
        ]);
    }

    /**
     * Create a new vendor.
     */
    public function store(CreateVendorRequest $request, string $orgId): JsonResponse
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

        $vendor = Vendor::withoutGlobalScopes()->create($data);
        $vendor->load('defaultExpenseAccount:id,code,name');

        return response()->json([
            'data' => $vendor,
            'meta' => [
                'message' => 'Vendor created successfully',
                'timestamp' => now()->toISOString(),
            ],
            'errors' => [],
        ], 201);
    }

    /**
     * View vendor details with bills.
     */
    public function show(Request $request, string $orgId, string $vendorId): JsonResponse
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

        $vendor = Vendor::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->with(['defaultExpenseAccount:id,code,name', 'bills' => fn ($q) => $q->orderBy('bill_date', 'desc')])
            ->findOrFail($vendorId);

        $data = $vendor->toArray();
        $data['total_outstanding'] = $vendor->totalOutstanding();

        return response()->json([
            'data' => $data,
            'meta' => [
                'timestamp' => now()->toISOString(),
            ],
            'errors' => [],
        ]);
    }

    /**
     * Update vendor details.
     */
    public function update(UpdateVendorRequest $request, string $orgId, string $vendorId): JsonResponse
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

        $vendor = Vendor::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->findOrFail($vendorId);

        $vendor->update($request->validated());
        $vendor->load('defaultExpenseAccount:id,code,name');

        return response()->json([
            'data' => $vendor,
            'meta' => [
                'message' => 'Vendor updated successfully',
                'timestamp' => now()->toISOString(),
            ],
            'errors' => [],
        ]);
    }

    /**
     * Soft delete vendor.
     */
    public function destroy(Request $request, string $orgId, string $vendorId): JsonResponse
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

        $vendor = Vendor::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->findOrFail($vendorId);

        $vendor->delete();

        return response()->json([
            'data' => ['id' => $vendor->id, 'deleted' => true],
            'meta' => [
                'message' => 'Vendor deleted successfully',
                'timestamp' => now()->toISOString(),
            ],
            'errors' => [],
        ]);
    }
}
