<?php

namespace App\Domain\Procurement\Models;

use App\Domain\Organization\Traits\BelongsToOrganization;
use App\Domain\Purchasing\Models\PurchaseBill;
use App\Domain\Purchasing\Models\Vendor;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PurchaseOrder extends Model
{
    use HasUuids, BelongsToOrganization, SoftDeletes;

    protected $table = 'purchase_orders';

    protected $fillable = [
        'organization_id',
        'vendor_id',
        'purchase_requisition_id',
        'po_number',
        'po_date',
        'expected_delivery_date',
        'status',
        'currency',
        'exchange_rate',
        'subtotal',
        'tax_amount',
        'total_amount',
        'payment_terms',
        'notes',
        'created_by',
        'issued_at',
    ];

    protected $casts = [
        'po_date' => 'date',
        'expected_delivery_date' => 'date',
        'exchange_rate' => 'decimal:6',
        'subtotal' => 'decimal:4',
        'tax_amount' => 'decimal:4',
        'total_amount' => 'decimal:4',
        'issued_at' => 'datetime',
    ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    public function requisition(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequisition::class, 'purchase_requisition_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class, 'purchase_order_id');
    }

    public function goodsReceipts(): HasMany
    {
        return $this->hasMany(GoodsReceipt::class, 'purchase_order_id');
    }

    public function bills(): HasMany
    {
        return $this->hasMany(PurchaseBill::class, 'purchase_order_id');
    }

    public function matches(): HasMany
    {
        return $this->hasMany(ThreeWayMatch::class, 'purchase_order_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
