<?php

namespace App\Domain\Billing\Models;

use App\Domain\Organization\Models\Organization;
use Illuminate\Database\Eloquent\Model;

class UsageMeter extends Model
{
    protected $fillable = [
        'organization_id',
        'metric_name',
        'billing_period',
        'usage_count',
    ];

    protected $casts = [
        'usage_count' => 'integer',
    ];

    public function organization(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }
}
