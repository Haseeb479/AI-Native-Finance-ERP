<?php

namespace App\Domain\Procurement\Models;

use App\Domain\Organization\Traits\BelongsToOrganization;
use App\Domain\Purchasing\Models\Vendor;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class GoodsReceipt extends Model
{
    use HasUuids, BelongsToOrganization, SoftDeletes;

    protected $table = 'goods_receipts';

    protected $fillable = [
        'organization_id',
        'purchase_order_id',
        'vendor_id',
        'warehouse_id',
        'grn_number',
        'received_date',
        'delivery_note_ref',
        'status',
        'notes',
        'received_by',
    ];

    protected $casts = [
        'received_date' => 'date',
    ];

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(GoodsReceiptLine::class, 'goods_receipt_id');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }
}
