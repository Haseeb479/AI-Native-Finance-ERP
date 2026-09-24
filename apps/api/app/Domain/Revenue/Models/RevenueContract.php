<?php

namespace App\Domain\Revenue\Models;

use App\Domain\Accounting\ChartOfAccounts\Models\Account;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Traits\BelongsToOrganization;
use App\Domain\Sales\Models\Customer;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RevenueContract extends Model
{
    use HasFactory, HasUuids, BelongsToOrganization;

    protected $table = 'revenue_contracts';

    protected $fillable = [
        'organization_id',
        'customer_id',
        'contract_number',
        'title',
        'start_date',
        'end_date',
        'total_contract_value',
        'currency',
        'recognition_method',
        'status',
        'deferred_revenue_account_id',
        'revenue_account_id',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'total_contract_value' => 'decimal:4',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function deferredRevenueAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'deferred_revenue_account_id');
    }

    public function revenueAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'revenue_account_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(RevenueSchedule::class, 'revenue_contract_id')->orderBy('schedule_date', 'asc');
    }

    public function totalRecognized(): float
    {
        return (float) $this->schedules()->where('status', 'posted')->sum('amount');
    }

    public function totalRemaining(): float
    {
        return max(0.0, (float) $this->total_contract_value - $this->totalRecognized());
    }
}
