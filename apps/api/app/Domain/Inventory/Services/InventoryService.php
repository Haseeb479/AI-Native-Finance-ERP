<?php

namespace App\Domain\Inventory\Services;

use App\Domain\Inventory\Models\InventoryValuationLayer;
use App\Domain\Inventory\Models\Product;
use App\Domain\Inventory\Models\ProductCategory;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\Models\WarehouseStock;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class InventoryService
{
    /**
     * Create product category.
     */
    public function createCategory(Organization $organization, array $data): ProductCategory
    {
        $slug = $data['slug'] ?? Str::slug($data['name']);

        return ProductCategory::withoutGlobalScopes()->create([
            'organization_id' => $organization->id,
            'name' => $data['name'],
            'slug' => $slug,
            'description' => $data['description'] ?? null,
        ]);
    }

    /**
     * Create warehouse.
     */
    public function createWarehouse(Organization $organization, array $data): Warehouse
    {
        return Warehouse::withoutGlobalScopes()->create([
            'organization_id' => $organization->id,
            'code' => strtoupper($data['code']),
            'name' => $data['name'],
            'city' => $data['city'] ?? null,
            'address' => $data['address'] ?? null,
            'is_active' => $data['is_active'] ?? true,
        ]);
    }

    /**
     * Create product / item.
     */
    public function createProduct(Organization $organization, array $data): Product
    {
        $inventoryAccount = $data['inventory_account_id'] ?? null;
        if (! $inventoryAccount) {
            $defaultInv = \App\Domain\Accounting\ChartOfAccounts\Models\Account::withoutGlobalScopes()
                ->where('organization_id', $organization->id)
                ->where('code', '1070') // Merchandise Inventory
                ->first();
            $inventoryAccount = $defaultInv?->id;
        }

        $cogsAccount = $data['cogs_account_id'] ?? null;
        if (! $cogsAccount) {
            $defaultCogs = \App\Domain\Accounting\ChartOfAccounts\Models\Account::withoutGlobalScopes()
                ->where('organization_id', $organization->id)
                ->where('code', '5010') // COGS - Purchases
                ->first();
            $cogsAccount = $defaultCogs?->id;
        }

        $revenueAccount = $data['revenue_account_id'] ?? null;
        if (! $revenueAccount) {
            $defaultRev = \App\Domain\Accounting\ChartOfAccounts\Models\Account::withoutGlobalScopes()
                ->where('organization_id', $organization->id)
                ->where('code', '4010') // Sales Revenue
                ->first();
            $revenueAccount = $defaultRev?->id;
        }

        return Product::withoutGlobalScopes()->create([
            'organization_id' => $organization->id,
            'category_id' => $data['category_id'] ?? null,
            'sku' => strtoupper(trim($data['sku'])),
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'unit_of_measure' => $data['unit_of_measure'] ?? 'pcs',
            'inventory_account_id' => $inventoryAccount,
            'cogs_account_id' => $cogsAccount,
            'revenue_account_id' => $revenueAccount,
            'valuation_method' => $data['valuation_method'] ?? 'weighted_average',
            'cost_price' => (float) ($data['cost_price'] ?? 0.0),
            'selling_price' => (float) ($data['selling_price'] ?? 0.0),
            'reorder_level' => (float) ($data['reorder_level'] ?? 0.0),
            'is_active' => $data['is_active'] ?? true,
        ]);
    }

    /**
     * Record stock receipt into warehouse.
     */
    public function recordStockReceipt(
        Product $product,
        Warehouse $warehouse,
        float $quantity,
        float $unitCost,
        string $refType = 'manual_receipt',
        ?string $refId = null,
        ?User $user = null,
        ?string $notes = null
    ): StockMovement {
        if ($quantity <= 0) {
            throw new InvalidArgumentException("Receipt quantity must be greater than zero.");
        }

        return DB::transaction(function () use ($product, $warehouse, $quantity, $unitCost, $refType, $refId, $user, $notes) {
            $stock = WarehouseStock::withoutGlobalScopes()->firstOrCreate(
                [
                    'organization_id' => $product->organization_id,
                    'product_id' => $product->id,
                    'warehouse_id' => $warehouse->id,
                ],
                [
                    'quantity_on_hand' => 0.0000,
                    'quantity_reserved' => 0.0000,
                    'weighted_average_cost' => $unitCost,
                    'total_valuation' => 0.0000,
                ]
            );

            $currentQty = (float) $stock->quantity_on_hand;
            $currentValuation = (float) $stock->total_valuation;
            $receiptTotal = round($quantity * $unitCost, 4);

            $newQty = round($currentQty + $quantity, 4);
            $newValuation = round($currentValuation + $receiptTotal, 4);
            $newWac = $newQty > 0 ? round($newValuation / $newQty, 4) : $unitCost;

            $stock->update([
                'quantity_on_hand' => $newQty,
                'weighted_average_cost' => $newWac,
                'total_valuation' => $newValuation,
            ]);

            // Update product's cost_price
            $product->update(['cost_price' => $newWac]);

            // Add FIFO lot layer
            InventoryValuationLayer::withoutGlobalScopes()->create([
                'organization_id' => $product->organization_id,
                'product_id' => $product->id,
                'warehouse_id' => $warehouse->id,
                'quantity_received' => $quantity,
                'quantity_remaining' => $quantity,
                'unit_cost' => $unitCost,
                'received_at' => now(),
            ]);

            // Record Movement
            return StockMovement::withoutGlobalScopes()->create([
                'organization_id' => $product->organization_id,
                'product_id' => $product->id,
                'warehouse_id' => $warehouse->id,
                'movement_type' => 'receipt',
                'reference_type' => $refType,
                'reference_id' => $refId,
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
                'total_cost' => $receiptTotal,
                'balance_after' => $newQty,
                'notes' => $notes,
                'created_by' => $user?->id,
            ]);
        });
    }

    /**
     * Record stock dispatch out of warehouse (calculates COGS).
     */
    public function recordStockDispatch(
        Product $product,
        Warehouse $warehouse,
        float $quantity,
        string $refType = 'sales_invoice',
        ?string $refId = null,
        ?User $user = null,
        ?string $notes = null
    ): array {
        if ($quantity <= 0) {
            throw new InvalidArgumentException("Dispatch quantity must be greater than zero.");
        }

        return DB::transaction(function () use ($product, $warehouse, $quantity, $refType, $refId, $user, $notes) {
            $stock = WarehouseStock::withoutGlobalScopes()
                ->where('organization_id', $product->organization_id)
                ->where('product_id', $product->id)
                ->where('warehouse_id', $warehouse->id)
                ->first();

            $currentQty = $stock ? (float) $stock->quantity_on_hand : 0.0;
            if ($currentQty < $quantity) {
                throw new InvalidArgumentException(
                    "Insufficient stock for product '{$product->name}' (SKU: {$product->sku}) in warehouse '{$warehouse->name}'. Available: {$currentQty}, Requested: {$quantity}"
                );
            }

            $totalCost = 0.0;

            if ($product->valuation_method === 'fifo') {
                // Deplete FIFO layers
                $remainingToDeplete = $quantity;
                $layers = InventoryValuationLayer::withoutGlobalScopes()
                    ->where('organization_id', $product->organization_id)
                    ->where('product_id', $product->id)
                    ->where('warehouse_id', $warehouse->id)
                    ->where('quantity_remaining', '>', 0)
                    ->orderBy('received_at', 'asc')
                    ->orderBy('created_at', 'asc')
                    ->get();

                foreach ($layers as $layer) {
                    if ($remainingToDeplete <= 0) {
                        break;
                    }

                    $layerQty = (float) $layer->quantity_remaining;
                    $layerUnitCost = (float) $layer->unit_cost;

                    if ($layerQty <= $remainingToDeplete) {
                        $cost = round($layerQty * $layerUnitCost, 4);
                        $totalCost += $cost;
                        $remainingToDeplete -= $layerQty;
                        $layer->update(['quantity_remaining' => 0.0000]);
                    } else {
                        $cost = round($remainingToDeplete * $layerUnitCost, 4);
                        $totalCost += $cost;
                        $layer->update(['quantity_remaining' => $layerQty - $remainingToDeplete]);
                        $remainingToDeplete = 0.0;
                    }
                }

                // If any residual without layer, fallback to product cost_price
                if ($remainingToDeplete > 0) {
                    $totalCost += round($remainingToDeplete * (float) $product->cost_price, 4);
                }
            } else {
                // Weighted Average Cost
                $unitCost = (float) $stock->weighted_average_cost;
                $totalCost = round($quantity * $unitCost, 4);
            }

            $newQty = round($currentQty - $quantity, 4);
            $newValuation = max(0.00, round((float) $stock->total_valuation - $totalCost, 4));

            $stock->update([
                'quantity_on_hand' => $newQty,
                'total_valuation' => $newValuation,
            ]);

            $effectiveUnitCost = $quantity > 0 ? round($totalCost / $quantity, 4) : 0.0;

            $movement = StockMovement::withoutGlobalScopes()->create([
                'organization_id' => $product->organization_id,
                'product_id' => $product->id,
                'warehouse_id' => $warehouse->id,
                'movement_type' => 'dispatch',
                'reference_type' => $refType,
                'reference_id' => $refId,
                'quantity' => -$quantity,
                'unit_cost' => $effectiveUnitCost,
                'total_cost' => $totalCost,
                'balance_after' => $newQty,
                'notes' => $notes,
                'created_by' => $user?->id,
            ]);

            return [
                'total_cost' => $totalCost,
                'unit_cost' => $effectiveUnitCost,
                'quantity' => $quantity,
                'movement' => $movement,
            ];
        });
    }

    /**
     * Stock Adjustment (e.g. physical inventory count discrepancy).
     */
    public function adjustStock(
        Product $product,
        Warehouse $warehouse,
        float $newQuantity,
        ?float $unitCost = null,
        string $reason = 'Physical count adjustment',
        ?User $user = null
    ): StockMovement {
        return DB::transaction(function () use ($product, $warehouse, $newQuantity, $unitCost, $reason, $user) {
            $stock = WarehouseStock::withoutGlobalScopes()->firstOrCreate(
                [
                    'organization_id' => $product->organization_id,
                    'product_id' => $product->id,
                    'warehouse_id' => $warehouse->id,
                ],
                [
                    'quantity_on_hand' => 0.0000,
                    'weighted_average_cost' => $unitCost ?? (float) $product->cost_price,
                    'total_valuation' => 0.0000,
                ]
            );

            $currentQty = (float) $stock->quantity_on_hand;
            $qtyDiff = round($newQuantity - $currentQty, 4);
            $cost = $unitCost ?? (float) $stock->weighted_average_cost;
            $valuationChange = round($qtyDiff * $cost, 4);

            $newValuation = max(0.00, round((float) $stock->total_valuation + $valuationChange, 4));

            $stock->update([
                'quantity_on_hand' => $newQuantity,
                'total_valuation' => $newValuation,
            ]);

            return StockMovement::withoutGlobalScopes()->create([
                'organization_id' => $product->organization_id,
                'product_id' => $product->id,
                'warehouse_id' => $warehouse->id,
                'movement_type' => 'adjustment',
                'reference_type' => 'manual_adjustment',
                'reference_id' => null,
                'quantity' => $qtyDiff,
                'unit_cost' => $cost,
                'total_cost' => abs($valuationChange),
                'balance_after' => $newQuantity,
                'notes' => $reason,
                'created_by' => $user?->id,
            ]);
        });
    }

    /**
     * Stock Transfer between warehouses.
     */
    public function transferStock(
        Product $product,
        Warehouse $sourceWarehouse,
        Warehouse $targetWarehouse,
        float $quantity,
        ?User $user = null
    ): array {
        if ($sourceWarehouse->id === $targetWarehouse->id) {
            throw new InvalidArgumentException("Source and target warehouses cannot be identical.");
        }

        return DB::transaction(function () use ($product, $sourceWarehouse, $targetWarehouse, $quantity, $user) {
            $dispatchResult = $this->recordStockDispatch(
                $product,
                $sourceWarehouse,
                $quantity,
                'warehouse_transfer',
                null,
                $user,
                "Transfer out to {$targetWarehouse->name}"
            );

            $receiptMovement = $this->recordStockReceipt(
                $product,
                $targetWarehouse,
                $quantity,
                $dispatchResult['unit_cost'],
                'warehouse_transfer',
                $dispatchResult['movement']->id,
                $user,
                "Transfer in from {$sourceWarehouse->name}"
            );

            return [
                'out_movement' => $dispatchResult['movement'],
                'in_movement' => $receiptMovement,
                'transferred_quantity' => $quantity,
                'unit_cost' => $dispatchResult['unit_cost'],
            ];
        });
    }

    /**
     * Get inventory valuation report across all products and warehouses.
     */
    public function getValuationReport(Organization $organization, ?string $warehouseId = null): array
    {
        $query = WarehouseStock::withoutGlobalScopes()
            ->where('warehouse_stock.organization_id', $organization->id)
            ->with(['product.category', 'warehouse']);

        if ($warehouseId) {
            $query->where('warehouse_id', $warehouseId);
        }

        $stocks = $query->get();

        $totalValuation = 0.0;
        $totalUnits = 0.0;
        $items = [];

        foreach ($stocks as $stock) {
            $product = $stock->product;
            $wh = $stock->warehouse;
            $qty = (float) $stock->quantity_on_hand;
            $wac = (float) $stock->weighted_average_cost;
            $valuation = (float) $stock->total_valuation;

            $totalValuation += $valuation;
            $totalUnits += $qty;

            $items[] = [
                'product_id' => $product->id,
                'sku' => $product->sku,
                'product_name' => $product->name,
                'category' => $product->category?->name ?? 'Uncategorized',
                'valuation_method' => $product->valuation_method,
                'warehouse_code' => $wh->code,
                'warehouse_name' => $wh->name,
                'quantity_on_hand' => $qty,
                'unit_cost' => $wac,
                'selling_price' => (float) $product->selling_price,
                'total_valuation' => $valuation,
                'reorder_status' => $qty <= (float) $product->reorder_level ? 'reorder_needed' : 'adequate',
            ];
        }

        return [
            'total_valuation' => round($totalValuation, 4),
            'total_units' => round($totalUnits, 4),
            'product_count' => count($items),
            'items' => $items,
        ];
    }
}
