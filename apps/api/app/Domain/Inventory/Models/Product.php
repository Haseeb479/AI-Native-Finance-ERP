<?php

namespace App\Domain\Inventory\Models;

use App\Domain\Accounting\ChartOfAccounts\Models\Account;
use App\Domain\Organization\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use HasUuids, BelongsToOrganization, SoftDeletes;

    protected $table = 'products';

    protected $fillable = [
        'organization_id',
        'category_id',
        'sku',
        'name',
        'description',
        'unit_of_measure',
        'inventory_account_id',
        'cogs_account_id',
        'revenue_account_id',
        'valuation_method',
        'cost_price',
        'selling_price',
        'reorder_level',
        'is_active',
    ];

    protected $casts = [
        'cost_price' => 'decimal:4',
        'selling_price' => 'decimal:4',
        'reorder_level' => 'decimal:4',
        'is_active' => 'boolean',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }

    public function inventoryAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'inventory_account_id');
    }

    public function cogsAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'cogs_account_id');
    }

    public function revenueAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'revenue_account_id');
    }

    public function warehouseStocks(): HasMany
    {
        return $this->hasMany(WarehouseStock::class, 'product_id');
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'product_id');
    }

    public function valuationLayers(): HasMany
    {
        return $this->hasMany(InventoryValuationLayer::class, 'product_id');
    }
}
