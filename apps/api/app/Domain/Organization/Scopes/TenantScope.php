<?php

namespace App\Domain\Organization\Scopes;

use App\Domain\Organization\Context\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class TenantScope implements Scope
{
    private static function context(): TenantContext
    {
        return app(TenantContext::class);
    }

    public static function setOrganizationId(?string $organizationId): void
    {
        static::context()->setOrganization($organizationId);
    }

    public static function getOrganizationId(): ?string
    {
        return static::context()->getOrganizationId();
    }

    /**
     * Apply the scope to a given Eloquent query builder.
     */
    public function apply(Builder $builder, Model $model): void
    {
        $orgId = static::getOrganizationId();
        if ($orgId) {
            $builder->where($model->qualifyColumn('organization_id'), $orgId);
        }
    }
}
