<?php

namespace App\Domain\Accounting\ChartOfAccounts\Models;

use App\Domain\Organization\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Account extends Model
{
    use HasUuids, BelongsToOrganization, SoftDeletes;

    protected $table = 'accounts';

    protected $fillable = [
        'organization_id',
        'account_type_id',
        'account_group_id',
        'parent_account_id',
        'code',
        'name',
        'description',
        'classification',
        'normal_balance',
        'currency',
        'is_active',
        'is_reconcilable',
        'is_system',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_reconcilable' => 'boolean',
        'is_system' => 'boolean',
    ];

    public function type(): BelongsTo
    {
        return $this->belongsTo(AccountType::class, 'account_type_id');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(AccountGroup::class, 'account_group_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'parent_account_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Account::class, 'parent_account_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeClassification(Builder $query, string $classification): Builder
    {
        return $query->where('classification', $classification);
    }

    public function scopeRoot(Builder $query): Builder
    {
        return $query->whereNull('parent_account_id');
    }
}
