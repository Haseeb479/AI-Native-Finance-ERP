<?php

namespace App\Domain\Inventory\Models;

use App\Domain\Organization\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WarehouseStock extends Model
{
    use HasUuids, BelongsToOrganization;

    protected $table = 'warehouse_stock';

    protected $fillable = [
        'organization_id',
        'product_id',
        'warehouse_id',
        'quantity_on_hand',
        'quantity_reserved',
        'weighted_average_cost',
        'total_valuation',
    ];

    protected $casts = [
        'quantity_on_hand' => 'decimal:4',
        'quantity_reserved' => 'decimal:4',
        'weighted_average_cost' => 'decimal:4',
        'total_valuation' => 'decimal:4',
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
