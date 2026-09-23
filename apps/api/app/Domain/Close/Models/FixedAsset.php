<?php

namespace App\Domain\Close\Models;

use App\Domain\Accounting\ChartOfAccounts\Models\Account;
use App\Domain\Organization\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FixedAsset extends Model
{
    use HasUuids, BelongsToOrganization;

    protected $table = 'fixed_assets';

    protected $fillable = [
        'organization_id',
        'asset_account_id',
        'accumulated_depreciation_account_id',
        'depreciation_expense_account_id',
        'asset_number',
        'name',
        'purchase_date',
        'purchase_cost',
        'salvage_value',
        'useful_life_months',
        'monthly_depreciation',
        'status',
        'last_depreciated_date',
    ];

    protected $casts = [
        'purchase_date' => 'date',
        'last_depreciated_date' => 'date',
        'purchase_cost' => 'decimal:4',
        'salvage_value' => 'decimal:4',
        'monthly_depreciation' => 'decimal:4',
        'useful_life_months' => 'integer',
    ];

    public function assetAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'asset_account_id');
    }

    public function accumulatedDepreciationAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'accumulated_depreciation_account_id');
    }

    public function depreciationExpenseAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'depreciation_expense_account_id');
    }
}
