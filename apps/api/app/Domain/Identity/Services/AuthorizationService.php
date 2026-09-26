<?php

namespace App\Domain\Identity\Services;

use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\UserOrganizationScope;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Entity;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

class AuthorizationService
{
    /**
     * Check if a user has permission within an organization and optional entity/branch/department scope.
     */
    public function can(
        User $user,
        string $permission,
        Organization|string $organization,
        ?Entity $entity = null,
        ?Branch $branch = null,
        ?Department $department = null
    ): bool {
        $orgId = $organization instanceof Organization ? $organization->id : $organization;
        $roleName = $user->roleInOrganization($organization);

        if (! $roleName) {
            return false;
        }

        // 1. Owner role has root tenant privileges
        if ($roleName === 'owner') {
            return $this->validateHierarchyConsistency($orgId, $entity, $branch, $department);
        }

        // 2. Check Role-Permission mapping
        $role = Role::where('name', $roleName)->with('permissions')->first();
        if (! $role || ! $role->permissions->contains('name', $permission)) {
            return false;
        }

        // 3. Validate Organization Hierarchy Consistency
        if (! $this->validateHierarchyConsistency($orgId, $entity, $branch, $department)) {
            return false;
        }

        // 4. Admin role has organization-wide scope across all entities and branches
        if ($roleName === 'admin') {
            return true;
        }

        // 5. Scoped Access Resolution for sub-entities, branches, and departments (P1-08)
        return $this->userHasScopeAccess($user, $orgId, $entity, $branch, $department);
    }

    /**
     * Authorize or throw AuthorizationException.
     */
    public function authorize(
        User $user,
        string $permission,
        Organization|string $organization,
        ?Entity $entity = null,
        ?Branch $branch = null,
        ?Department $department = null
    ): void {
        if (! $this->can($user, $permission, $organization, $entity, $branch, $department)) {
            throw new AuthorizationException(
                "Unauthorized: User lacks '{$permission}' permission or scoped access for organization {$organization}."
            );
        }
    }

    /**
     * Verify whether a user can access a specific entity within an organization.
     */
    public function canAccessEntity(User $user, Organization|string $organization, Entity|string $entity): bool
    {
        $orgId = $organization instanceof Organization ? $organization->id : $organization;
        $entityModel = $entity instanceof Entity ? $entity : Entity::where('organization_id', $orgId)->find($entity);

        if (! $entityModel || $entityModel->organization_id !== $orgId) {
            return false;
        }

        $roleName = $user->roleInOrganization($organization);
        if (in_array($roleName, ['owner', 'admin'])) {
            return true;
        }

        return $this->userHasScopeAccess($user, $orgId, $entityModel);
    }

    /**
     * Verify whether a user can access a specific branch.
     */
    public function canAccessBranch(User $user, Organization|string $organization, Branch|string $branch): bool
    {
        $orgId = $organization instanceof Organization ? $organization->id : $organization;
        $branchModel = $branch instanceof Branch ? $branch : Branch::where('organization_id', $orgId)->find($branch);

        if (! $branchModel || $branchModel->organization_id !== $orgId) {
            return false;
        }

        $roleName = $user->roleInOrganization($organization);
        if (in_array($roleName, ['owner', 'admin'])) {
            return true;
        }

        return $this->userHasScopeAccess($user, $orgId, $branchModel->entity, $branchModel);
    }

    /**
     * Enforce Maker-Checker Separation of Duties (P0-10 & P1-07).
     */
    public function assertMakerCheckerSeparation(?User $maker, ?User $approver, string $action = 'approve'): void
    {
        if ($maker && $approver && (int) $maker->id === (int) $approver->id) {
            throw new AuthorizationException(
                "Separation of Duties violation: A user cannot {$action} their own transaction or journal draft."
            );
        }
    }

    /**
     * Validates that child entities/branches/departments strictly belong to the specified organization.
     */
    private function validateHierarchyConsistency(
        string $orgId,
        ?Entity $entity = null,
        ?Branch $branch = null,
        ?Department $department = null
    ): bool {
        if ($entity && $entity->organization_id !== $orgId) {
            return false;
        }

        if ($branch) {
            if ($branch->organization_id !== $orgId) {
                return false;
            }
            if ($entity && $branch->entity_id !== $entity->id) {
                return false;
            }
        }

        if ($department) {
            if ($department->organization_id !== $orgId) {
                return false;
            }
            if ($branch && $department->branch_id !== $branch->id) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if user's assigned scopes match the requested entity/branch/department.
     */
    private function userHasScopeAccess(
        User $user,
        string $orgId,
        ?Entity $entity = null,
        ?Branch $branch = null,
        ?Department $department = null
    ): bool {
        $scopes = UserOrganizationScope::where('user_id', $user->id)
            ->where('organization_id', $orgId)
            ->get();

        // If no explicit sub-scopes are assigned, user has full tenant-level scope of their role
        if ($scopes->isEmpty()) {
            return true;
        }

        foreach ($scopes as $scope) {
            // Unrestricted entity scope allows all entities
            if ($scope->entity_id === null) {
                return true;
            }

            if ($entity && $scope->entity_id === $entity->id) {
                // If branch is specified, check branch match
                if ($branch) {
                    if ($scope->branch_id === null || $scope->branch_id === $branch->id) {
                        if ($department) {
                            if ($scope->department_id === null || $scope->department_id === $department->id) {
                                return true;
                            }
                        } else {
                            return true;
                        }
                    }
                } else {
                    return true;
                }
            }
        }

        return false;
    }
}
