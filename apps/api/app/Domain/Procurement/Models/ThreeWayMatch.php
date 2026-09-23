<?php

namespace App\Domain\Procurement\Models;

use App\Domain\Organization\Traits\BelongsToOrganization;
use App\Domain\Purchasing\Models\PurchaseBill;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ThreeWayMatch extends Model
{
    use HasUuids, BelongsToOrganization;

    protected $table = 'three_way_matches';

    protected $fillable = [
        'organization_id',
        'purchase_bill_id',
        'purchase_order_id',
        'goods_receipt_id',
        'status',
        'match_outcome',
        'tolerance_percentage',
        'po_total',
        'grn_total',
        'bill_total',
        'price_variance',
        'price_variance_percentage',
        'quantity_variance',
        'discrepancies',
        'waiver_reason',
        'waived_by',
        'waived_at',
        'matched_by',
        'matched_at',
    ];

    protected $casts = [
        'tolerance_percentage' => 'decimal:2',
        'po_total' => 'decimal:4',
        'grn_total' => 'decimal:4',
        'bill_total' => 'decimal:4',
        'price_variance' => 'decimal:4',
        'price_variance_percentage' => 'decimal:4',
        'quantity_variance' => 'decimal:4',
        'discrepancies' => 'array',
        'waived_at' => 'datetime',
        'matched_at' => 'datetime',
    ];

    public function purchaseBill(): BelongsTo
    {
        return $this->belongsTo(PurchaseBill::class, 'purchase_bill_id');
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function goodsReceipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class, 'goods_receipt_id');
    }

    public function matcher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'matched_by');
    }

    public function waiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'waived_by');
    }

    public function isMatched(): bool
    {
        return in_array($this->status, ['matched', 'waived']);
    }

    public function isException(): bool
    {
        return $this->status === 'exception';
    }
}
