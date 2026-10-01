<?php

namespace App\Domain\Billing\Models;

use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'name',
        'price_monthly',
        'currency',
        'max_seats',
        'monthly_transaction_limit',
        'monthly_ai_query_limit',
        'max_storage_mb',
        'features',
        'is_active',
    ];

    protected $casts = [
        'price_monthly' => 'string',
        'max_seats' => 'integer',
        'monthly_transaction_limit' => 'integer',
        'monthly_ai_query_limit' => 'integer',
        'max_storage_mb' => 'integer',
        'features' => 'array',
        'is_active' => 'boolean',
    ];

    public function subscriptions(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Subscription::class, 'plan_id');
    }
}
