<?php

namespace App\Domain\Accounting\ChartOfAccounts\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AccountType extends Model
{
    protected $table = 'account_types';

    protected $fillable = [
        'name',
        'slug',
        'classification',
        'normal_balance',
        'description',
    ];

    public function groups(): HasMany
    {
        return $this->hasMany(AccountGroup::class, 'account_type_id');
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class, 'account_type_id');
    }
}
