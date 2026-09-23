<?php

namespace App\Domain\Procurement\Models;

use App\Domain\Organization\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseRequisitionLine extends Model
{
    use HasUuids, BelongsToOrganization;

    protected $table = 'purchase_requisition_lines';

    protected $fillable = [
        'organization_id',
        'purchase_requisition_id',
        'line_number',
        'item_code',
        'description',
        'quantity',
        'estimated_unit_price',
        'estimated_subtotal',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'estimated_unit_price' => 'decimal:4',
        'estimated_subtotal' => 'decimal:4',
    ];

    public function requisition(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequisition::class, 'purchase_requisition_id');
    }
}
