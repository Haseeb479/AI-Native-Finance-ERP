<?php

namespace App\Domain\Banking\Models;

use App\Domain\Accounting\ChartOfAccounts\Models\Account;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class BankAccount extends Model
{
    use HasFactory, HasUuids, SoftDeletes, BelongsToOrganization;

    protected $table = 'bank_accounts';

    protected $fillable = [
        'organization_id',
        'account_id',
        'bank_name',
        'account_title',
        'account_number',
        'iban',
        'branch_name',
        'branch_code',
        'currency',
        'opening_balance',
        'current_balance',
        'is_active',
    ];

    protected $casts = [
        'opening_balance' => 'decimal:4',
        'current_balance' => 'decimal:4',
        'is_active' => 'boolean',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function chartAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    public function statements(): HasMany
    {
        return $this->hasMany(BankStatement::class, 'bank_account_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(BankTransaction::class, 'bank_account_id');
    }

    public function unreconciledTransactionsCount(): int
    {
        return $this->transactions()->where('reconciliation_status', 'unreconciled')->count();
    }
}
