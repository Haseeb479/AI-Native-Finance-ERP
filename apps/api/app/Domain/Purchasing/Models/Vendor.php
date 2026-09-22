<?php

namespace App\Domain\Purchasing\Models;

use App\Domain\Accounting\ChartOfAccounts\Models\Account;
use App\Domain\Organization\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Vendor extends Model
{
    use HasUuids, BelongsToOrganization, SoftDeletes;

    protected $table = 'vendors';

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
        'default_expense_account_id',
        'is_active',
    ];

    protected $casts = [
        'payment_terms_days' => 'integer',
        'is_active' => 'boolean',
    ];

    public function defaultExpenseAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'default_expense_account_id');
    }

    public function bills(): HasMany
    {
        return $this->hasMany(PurchaseBill::class, 'vendor_id');
    }

    public function totalOutstanding(): float
    {
        return (float) $this->bills()
            ->whereIn('status', ['received', 'partial'])
            ->get()
            ->sum(fn ($bill) => (float) $bill->balanceDue());
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
