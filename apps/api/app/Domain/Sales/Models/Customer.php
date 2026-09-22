<?php

namespace App\Domain\Sales\Models;

use App\Domain\Organization\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use HasUuids, BelongsToOrganization, SoftDeletes;

    protected $table = 'customers';

    protected $fillable = [
        'organization_id',
        'name',
        'legal_name',
        'ntn',
        'strn',
        'email',
        'phone',
        'address_line1',
        'city',
        'province',
        'country',
        'payment_terms_days',
        'credit_limit',
        'is_active',
    ];

    protected $casts = [
        'payment_terms_days' => 'integer',
        'credit_limit' => 'decimal:4',
        'is_active' => 'boolean',
    ];

    public function invoices(): HasMany
    {
        return $this->hasMany(SalesInvoice::class, 'customer_id');
    }

    public function totalOutstanding(): float
    {
        return (float) $this->invoices()
            ->whereIn('status', ['sent', 'partial'])
            ->get()
            ->sum(fn ($inv) => (float) $inv->balanceDue());
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
