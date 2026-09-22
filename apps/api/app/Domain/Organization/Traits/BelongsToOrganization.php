<?php

namespace App\Domain\Organization\Traits;

use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToOrganization
{
    /**
     * Boot the trait to attach global TenantScope and auto-populate organization_id.
     */
    protected static function bootBelongsToOrganization(): void
    {
        static::addGlobalScope(new TenantScope());

        static::creating(function ($model) {
            if (empty($model->organization_id) && TenantScope::getOrganizationId()) {
                $model->organization_id = TenantScope::getOrganizationId();
            }
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }
}
