<?php

namespace App\Domain\Procurement\Models;

use App\Domain\Organization\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrderLine extends Model
{
    use HasUuids, BelongsToOrganization;

    protected $table = 'purchase_order_lines';

    protected $fillable = [
        'organization_id',
        'purchase_order_id',
        'line_number',
        'product_id',
        'description',
        'quantity',
        'unit_price',
        'subtotal',
        'received_quantity',
        'billed_quantity',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'unit_price' => 'decimal:4',
        'subtotal' => 'decimal:4',
        'received_quantity' => 'decimal:4',
        'billed_quantity' => 'decimal:4',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function goodsReceiptLines(): HasMany
    {
        return $this->hasMany(GoodsReceiptLine::class, 'purchase_order_line_id');
    }
}
