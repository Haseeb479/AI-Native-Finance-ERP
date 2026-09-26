<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function organizations(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(\App\Domain\Organization\Models\Organization::class, 'organization_user')
            ->withPivot('role', 'is_default')
            ->withTimestamps();
    }

    public function roleInOrganization(string|\App\Domain\Organization\Models\Organization $organization): ?string
    {
        $orgId = is_string($organization) ? $organization : $organization->id;
        $membership = $this->organizations()->where('organizations.id', $orgId)->first();

        return $membership?->pivot?->role;
    }

    /**
     * OWNER WILDCARD & SEPARATION OF DUTIES POLICY (P0-10):
     * The organization 'owner' role holds top-level tenant authority and defaults to wildcard
     * permissions for operational continuity.
     * When strict Separation of Duties (SoD) is enabled, maker-checker invariants still apply:
     * a maker cannot post or approve their own draft records.
     */
    public function hasPermissionInOrganization(string $permission, string|\App\Domain\Organization\Models\Organization $organization): bool
    {
        $roleName = $this->roleInOrganization($organization);

        if (! $roleName) {
            return false;
        }

        if ($roleName === 'owner') {
            return true;
        }

        $role = \App\Domain\Identity\Models\Role::where('name', $roleName)
            ->with('permissions')
            ->first();

        if (! $role) {
            return false;
        }

        return $role->permissions->contains('name', $permission);
    }

    /**
     * Get verified server-side permissions for a user in a given organization.
     */
    public function getPermissionsForOrganization(string|\App\Domain\Organization\Models\Organization $organization): array
    {
        $roleName = $this->roleInOrganization($organization);

        if (! $roleName) {
            return [];
        }

        if ($roleName === 'owner') {
            return ['*'];
        }

        $role = \App\Domain\Identity\Models\Role::where('name', $roleName)
            ->with('permissions')
            ->first();

        if (! $role) {
            return [];
        }

        return $role->permissions->pluck('name')->all();
    }
}
