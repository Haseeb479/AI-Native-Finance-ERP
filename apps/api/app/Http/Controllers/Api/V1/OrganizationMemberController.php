<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Identity\Models\Role;
use App\Domain\Organization\Models\Organization;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\AddMemberRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrganizationMemberController extends Controller
{
    /**
     * List all members of an organization with their assigned roles.
     */
    public function index(Request $request, string $orgId): JsonResponse
    {
        $organization = Organization::find($orgId);

        if (! $organization) {
            return response()->json(['data' => null, 'errors' => ['Organization not found.']], 404);
        }

        if (! $request->user()->hasPermissionInOrganization('users.view', $organization)) {
            return response()->json(['data' => null, 'errors' => ['Unauthorized. Permission users.view required.']], 403);
        }

        $members = $organization->users()->get()->map(function ($user) use ($organization) {
            $roleName = $user->pivot->role;
            $role = Role::where('name', $roleName)->with('permissions')->first();

            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $roleName,
                'role_label' => $role?->label ?? ucfirst($roleName),
                'permissions' => $roleName === 'owner' ? ['*'] : $role?->permissions->pluck('name')->toArray(),
                'joined_at' => $user->pivot->created_at?->toIso8601String(),
            ];
        });

        return response()->json([
            'data' => [
                'members' => $members,
            ],
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'total' => $members->count(),
            ],
            'errors' => [],
        ], 200);
    }

    /**
     * Add a user to the organization with a designated role.
     */
    public function store(AddMemberRequest $request, string $orgId): JsonResponse
    {
        $organization = Organization::find($orgId);

        if (! $organization) {
            return response()->json(['data' => null, 'errors' => ['Organization not found.']], 404);
        }

        if (! $request->user()->hasPermissionInOrganization('users.manage', $organization)) {
            return response()->json(['data' => null, 'errors' => ['Unauthorized. Permission users.manage required.']], 403);
        }

        $validated = $request->validated();
        $targetUser = User::where('email', $validated['email'])->first();

        if (! $targetUser) {
            return response()->json([
                'data' => null,
                'errors' => ['No registered user found with that email address. User must register first.'],
            ], 404);
        }

        // Check if already a member
        if ($organization->users()->where('users.id', $targetUser->id)->exists()) {
            return response()->json([
                'data' => null,
                'errors' => ['User is already a member of this organization.'],
            ], 422);
        }

        $organization->users()->attach($targetUser->id, [
            'role' => $validated['role'],
            'is_default' => false,
        ]);

        return response()->json([
            'data' => [
                'message' => 'Member added successfully.',
                'member' => [
                    'id' => $targetUser->id,
                    'name' => $targetUser->name,
                    'email' => $targetUser->email,
                    'role' => $validated['role'],
                ],
            ],
            'meta' => [
                'timestamp' => now()->toIso8601String(),
            ],
            'errors' => [],
        ], 201);
    }

    /**
     * Remove a member from the organization.
     */
    public function destroy(Request $request, string $orgId, int $userId): JsonResponse
    {
        $organization = Organization::find($orgId);

        if (! $organization) {
            return response()->json(['data' => null, 'errors' => ['Organization not found.']], 404);
        }

        if (! $request->user()->hasPermissionInOrganization('users.manage', $organization)) {
            return response()->json(['data' => null, 'errors' => ['Unauthorized. Permission users.manage required.']], 403);
        }

        // Check target membership
        $targetMember = $organization->users()->where('users.id', $userId)->first();
        if (! $targetMember) {
            return response()->json(['data' => null, 'errors' => ['Member not found in this organization.']], 404);
        }

        // Prevent deleting the sole owner
        if ($targetMember->pivot->role === 'owner') {
            $ownerCount = $organization->users()->wherePivot('role', 'owner')->count();
            if ($ownerCount <= 1) {
                return response()->json([
                    'data' => null,
                    'errors' => ['Cannot remove the primary owner. Transfer ownership first.'],
                ], 422);
            }
        }

        $organization->users()->detach($userId);

        return response()->json([
            'data' => [
                'message' => 'Member removed successfully.',
            ],
            'meta' => [
                'timestamp' => now()->toIso8601String(),
            ],
            'errors' => [],
        ], 200);
    }
}
