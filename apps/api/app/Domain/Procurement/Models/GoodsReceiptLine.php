<?php

namespace App\Domain\Procurement\Models;

use App\Domain\Organization\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoodsReceiptLine extends Model
{
    use HasUuids, BelongsToOrganization;

    protected $table = 'goods_receipt_lines';

    protected $fillable = [
        'organization_id',
        'goods_receipt_id',
        'purchase_order_line_id',
        'product_id',
        'quantity_received',
        'unit_cost',
        'subtotal',
    ];

    protected $casts = [
        'quantity_received' => 'decimal:4',
        'unit_cost' => 'decimal:4',
        'subtotal' => 'decimal:4',
    ];

    public function goodsReceipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class, 'goods_receipt_id');
    }

    public function purchaseOrderLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderLine::class, 'purchase_order_line_id');
    }
}
