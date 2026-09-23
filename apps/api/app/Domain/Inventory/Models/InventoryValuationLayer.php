<?php

namespace App\Domain\Inventory\Models;

use App\Domain\Organization\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryValuationLayer extends Model
{
    use HasUuids, BelongsToOrganization;

    protected $table = 'inventory_valuation_layers';

    protected $fillable = [
        'organization_id',
        'product_id',
        'warehouse_id',
        'quantity_received',
        'quantity_remaining',
        'unit_cost',
        'received_at',
    ];

    protected $casts = [
        'quantity_received' => 'decimal:4',
        'quantity_remaining' => 'decimal:4',
        'unit_cost' => 'decimal:4',
        'received_at' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }
}
