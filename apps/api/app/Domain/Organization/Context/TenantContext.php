<?php

namespace App\Domain\Organization\Context;

use App\Domain\Organization\Models\Organization;
use App\Models\User;

class TenantContext
{
    private ?string $organizationId = null;
    private ?Organization $organization = null;
    private ?string $entityId = null;
    private ?string $branchId = null;
    private ?User $user = null;
    private array $permissions = [];

    public function setOrganization(Organization|string|null $organization): self
    {
        if ($organization instanceof Organization) {
            $this->organization = $organization;
            $this->organizationId = $organization->id;
        } else {
            $this->organizationId = $organization;
            $this->organization = null;
        }

        return $this;
    }

    public function getOrganizationId(): ?string
    {
        return $this->organizationId;
    }

    public function getOrganization(): ?Organization
    {
        if (! $this->organization && $this->organizationId) {
            $this->organization = Organization::find($this->organizationId);
        }

        return $this->organization;
    }

    public function setEntityId(?string $entityId): self
    {
        $this->entityId = $entityId;
        return $this;
    }

    public function getEntityId(): ?string
    {
        return $this->entityId;
    }

    public function setBranchId(?string $branchId): self
    {
        $this->branchId = $branchId;
        return $this;
    }

    public function getBranchId(): ?string
    {
        return $this->branchId;
    }

    public function setUser(?User $user): self
    {
        $this->user = $user;
        if ($user && $this->organizationId) {
            $this->permissions = $user->getPermissionsForOrganization($this->organizationId);
        }

        return $this;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setPermissions(array $permissions): self
    {
        $this->permissions = $permissions;
        return $this;
    }

    public function getPermissions(): array
    {
        return $this->permissions;
    }

    public function hasPermission(string $permission): bool
    {
        return in_array('*', $this->permissions, true) || in_array($permission, $this->permissions, true);
    }

    /**
     * Clear the tenant context to prevent bleed across requests or queued jobs.
     */
    public function clear(): void
    {
        $this->organizationId = null;
        $this->organization = null;
        $this->entityId = null;
        $this->branchId = null;
        $this->user = null;
        $this->permissions = [];
    }
}
