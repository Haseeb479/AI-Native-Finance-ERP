<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Entity;
use App\Domain\Organization\Models\Organization;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\CreateOrganizationRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrganizationController extends Controller
{
    /**
     * List all organizations belonging to the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        $organizations = $request->user()
            ->organizations()
            ->withCount(['entities', 'branches'])
            ->get();

        return response()->json([
            'data' => [
                'organizations' => $organizations->map(function ($org) {
                    return [
                        'id' => $org->id,
                        'name' => $org->name,
                        'legal_name' => $org->legal_name,
                        'ntn' => $org->ntn,
                        'strn' => $org->strn,
                        'base_currency' => $org->base_currency,
                        'role' => $org->pivot->role,
                        'is_default' => (bool) $org->pivot->is_default,
                        'entities_count' => $org->entities_count,
                        'branches_count' => $org->branches_count,
                        'created_at' => $org->created_at?->toIso8601String(),
                    ];
                }),
            ],
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'total' => $organizations->count(),
            ],
            'errors' => [],
        ], 200);
    }

    /**
     * Create a new organization with default primary entity and primary branch in a transaction.
     */
    public function store(CreateOrganizationRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $user = $request->user();

        $organization = DB::transaction(function () use ($validated, $user) {
            $org = Organization::create([
                'name' => $validated['name'],
                'legal_name' => $validated['legal_name'],
                'ntn' => $validated['ntn'] ?? null,
                'strn' => $validated['strn'] ?? null,
                'country_code' => $validated['country_code'] ?? 'PK',
                'base_currency' => $validated['base_currency'] ?? 'PKR',
                'fiscal_year_start_month' => $validated['fiscal_year_start_month'] ?? 7,
                'status' => 'active',
            ]);

            // Attach user as Organization Owner
            $isFirstOrg = $user->organizations()->count() === 0;
            $org->users()->attach($user->id, [
                'role' => 'owner',
                'is_default' => $isFirstOrg,
            ]);

            // Create Primary Legal Entity
            $entity = Entity::create([
                'organization_id' => $org->id,
                'name' => $validated['primary_entity_name'] ?? $org->name,
                'code' => 'HQ',
                'currency' => $org->base_currency,
                'is_primary' => true,
                'status' => 'active',
            ]);

            // Create Primary Operating Branch
            Branch::create([
                'organization_id' => $org->id,
                'entity_id' => $entity->id,
                'name' => $validated['primary_branch_name'] ?? 'Head Office',
                'code' => 'BR-01',
                'city' => $validated['city'] ?? 'Karachi',
                'status' => 'active',
            ]);

            return $org;
        });

        return response()->json([
            'data' => [
                'organization' => [
                    'id' => $organization->id,
                    'name' => $organization->name,
                    'legal_name' => $organization->legal_name,
                    'ntn' => $organization->ntn,
                    'strn' => $organization->strn,
                    'base_currency' => $organization->base_currency,
                    'fiscal_year_start_month' => $organization->fiscal_year_start_month,
                ],
            ],
            'meta' => [
                'timestamp' => now()->toIso8601String(),
            ],
            'errors' => [],
        ], 201);
    }

    /**
     * Get organization details, verifying user membership.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $organization = $request->user()
            ->organizations()
            ->with(['entities.branches', 'departments'])
            ->where('organizations.id', $id)
            ->first();

        if (! $organization) {
            return response()->json([
                'data' => null,
                'meta' => [
                    'timestamp' => now()->toIso8601String(),
                ],
                'errors' => ['Organization not found or access denied.'],
            ], 404);
        }

        return response()->json([
            'data' => [
                'organization' => [
                    'id' => $organization->id,
                    'name' => $organization->name,
                    'legal_name' => $organization->legal_name,
                    'ntn' => $organization->ntn,
                    'strn' => $organization->strn,
                    'base_currency' => $organization->base_currency,
                    'role' => $organization->pivot->role,
                    'entities' => $organization->entities,
                    'departments' => $organization->departments,
                ],
            ],
            'meta' => [
                'timestamp' => now()->toIso8601String(),
            ],
            'errors' => [],
        ], 200);
    }
}
