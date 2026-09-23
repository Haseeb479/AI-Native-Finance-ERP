<?php

namespace App\Domain\Purchasing\Models;

use App\Domain\Accounting\ChartOfAccounts\Models\Account;
use App\Domain\Organization\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseBillLine extends Model
{
    use HasUuids, BelongsToOrganization;

    protected $table = 'purchase_bill_lines';

    protected $fillable = [
        'organization_id',
        'purchase_bill_id',
        'purchase_order_line_id',
        'product_id',
        'expense_account_id',
        'line_number',
        'description',
        'quantity',
        'unit_price',
        'subtotal',
    ];

    protected $casts = [
        'line_number' => 'integer',
        'quantity' => 'decimal:4',
        'unit_price' => 'decimal:4',
        'subtotal' => 'decimal:4',
    ];

    public function bill(): BelongsTo
    {
        return $this->belongsTo(PurchaseBill::class, 'purchase_bill_id');
    }

    public function expenseAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'expense_account_id');
    }
}
