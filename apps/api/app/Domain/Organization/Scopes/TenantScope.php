<?php

namespace App\Domain\Organization\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class TenantScope implements Scope
{
    /**
     * The active organization ID for the current request.
     */
    protected static ?string $currentOrganizationId = null;

    public static function setOrganizationId(?string $organizationId): void
    {
        static::$currentOrganizationId = $organizationId;
    }

    public static function getOrganizationId(): ?string
    {
        return static::$currentOrganizationId;
    }

    /**
     * Apply the scope to a given Eloquent query builder.
     */
    public function apply(Builder $builder, Model $model): void
    {
        if (static::$currentOrganizationId) {
            $builder->where($model->qualifyColumn('organization_id'), static::$currentOrganizationId);
        }
    }
}
