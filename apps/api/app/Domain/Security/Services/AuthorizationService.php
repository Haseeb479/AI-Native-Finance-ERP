<?php

namespace App\Domain\Security\Services;

use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

class AuthorizationService
{
    /**
     * Map of standard capabilities to allowed default roles.
     */
    protected array $roleCapabilities = [
        'owner' => ['*'],
        'admin' => [
            'organizations.view', 'organizations.update', 'members.manage',
            'accounts.manage', 'journals.view', 'journals.create', 'journals.post', 'journals.reverse',
            'invoices.view', 'invoices.create', 'invoices.post', 'invoices.pay',
            'bills.view', 'bills.create', 'bills.post', 'bills.pay',
            'banking.view', 'banking.reconcile', 'banking.import',
            'reports.view', 'reports.export', 'copilot.access', 'audit.view',
        ],
        'accountant' => [
            'organizations.view', 'accounts.manage',
            'journals.view', 'journals.create', 'journals.post', 'journals.reverse',
            'invoices.view', 'invoices.create', 'invoices.post', 'invoices.pay',
            'bills.view', 'bills.create', 'bills.post', 'bills.pay',
            'banking.view', 'banking.reconcile', 'banking.import',
            'reports.view', 'reports.export', 'copilot.access',
        ],
        'finance_manager' => [
            'organizations.view',
            'journals.view', 'journals.create', 'journals.post',
            'invoices.view', 'invoices.create', 'invoices.post', 'invoices.pay',
            'bills.view', 'bills.create', 'bills.post', 'bills.pay',
            'banking.view', 'banking.reconcile',
            'reports.view', 'reports.export', 'copilot.access',
        ],
        'staff' => [
            'organizations.view',
            'invoices.view', 'invoices.create',
            'bills.view', 'bills.create',
            'reports.view',
        ],
        'auditor' => [
            'organizations.view',
            'journals.view', 'invoices.view', 'bills.view',
            'banking.view', 'reports.view', 'reports.export',
            'audit.view',
        ],
    ];

    /**
     * Authorize user capability within organization, entity, and branch scope.
     * P1-07: Centralized authorization service.
     * P1-08: Explicit entity / branch / department authorization scopes.
     */
    public function can(
        User $user,
        string $permission,
        Organization|string $organization,
        ?string $entityId = null,
        ?string $branchId = null,
        ?string $departmentId = null
    ): bool {
        $orgId = is_string($organization) ? $organization : $organization->id;
        $roleName = $user->roleInOrganization($orgId);

        if (! $roleName) {
            return false;
        }

        // Owner has wildcard permissions
        if ($roleName === 'owner') {
            return true;
        }

        // 1. Capability Permission Check
        $allowedPermissions = $this->roleCapabilities[$roleName] ?? [];
        $hasPermission = in_array('*', $allowedPermissions, true) || in_array($permission, $allowedPermissions, true);

        if (! $hasPermission) {
            return false;
        }

        // 2. Entity / Branch Scope Validation (P1-08)
        if ($entityId !== null && ! $this->isEntityAllowed($user, $orgId, $entityId)) {
            return false;
        }

        if ($branchId !== null && ! $this->isBranchAllowed($user, $orgId, $branchId)) {
            return false;
        }

        return true;
    }

    /**
     * Enforce strict Separation of Duties (SoD).
     * Rule: A maker (creator) cannot approve or post financial mutations they authored.
     */
    public function assertSeparationOfDuties(User $user, object $record, string $action = 'post'): void
    {
        $creatorId = $record->created_by ?? $record->user_id ?? null;

        if ($creatorId !== null && (string) $creatorId === (string) $user->id) {
            throw new AuthorizationException(
                "Separation of Duties (SoD) Violation: You cannot {$action} a record that you created."
            );
        }
    }

    /**
     * Helper to verify entity scope.
     */
    protected function isEntityAllowed(User $user, string $orgId, string $entityId): bool
    {
        // If user has specific entity bindings in metadata/cache, enforce them
        $assignedEntities = $user->entity_scopes ?? null;
        if ($assignedEntities === null || empty($assignedEntities)) {
            return true; // No entity restriction configured for user
        }

        return in_array($entityId, $assignedEntities, true);
    }

    /**
     * Helper to verify branch scope.
     */
    protected function isBranchAllowed(User $user, string $orgId, string $branchId): bool
    {
        $assignedBranches = $user->branch_scopes ?? null;
        if ($assignedBranches === null || empty($assignedBranches)) {
            return true; // No branch restriction configured for user
        }

        return in_array($branchId, $assignedBranches, true);
    }
}
