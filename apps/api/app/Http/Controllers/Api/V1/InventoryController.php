<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Inventory\Models\Product;
use App\Domain\Inventory\Models\ProductCategory;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\Services\CogsEngine;
use App\Domain\Inventory\Services\InventoryService;
use App\Domain\Organization\Models\Organization;
use App\Domain\Sales\Models\SalesInvoice;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventoryController extends Controller
{
    public function __construct(
        protected InventoryService $inventoryService,
        protected CogsEngine $cogsEngine
    ) {}

    /**
     * List inventory products.
     */
    public function indexProducts(Request $request, string $orgId): JsonResponse
    {
        $query = Product::withoutGlobalScopes()
            ->where('organization_id', $orgId)
            ->with(['category', 'warehouseStocks.warehouse']);

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->query('category_id'));
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', filter_var($request->query('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        $products = $query->paginate(20);

        return response()->json([
            'data' => $products->items(),
            'meta' => [
                'current_page' => $products->currentPage(),
                'total' => $products->total(),
                'per_page' => $products->perPage(),
            ],
            'errors' => [],
        ]);
    }

    /**
     * Create product / SKU.
     */
    public function storeProduct(Request $request, string $orgId): JsonResponse
    {
        $organization = Organization::findOrFail($orgId);

        $validated = $request->validate([
            'sku' => ['required', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:150'],
            'category_id' => ['nullable', 'uuid'],
            'unit_of_measure' => ['nullable', 'string', 'max:20'],
            'valuation_method' => ['nullable', 'in:weighted_average,fifo'],
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            'selling_price' => ['nullable', 'numeric', 'min:0'],
            'reorder_level' => ['nullable', 'numeric', 'min:0'],
            'description' => ['nullable', 'string'],
        ]);

        $product = $this->inventoryService->createProduct($organization, $validated);

        return response()->json([
            'data' => $product,
            'meta' => ['message' => 'Product created successfully.'],
            'errors' => [],
        ], 201);
    }

    /**
     * List warehouses.
     */
    public function indexWarehouses(Request $request, string $orgId): JsonResponse
    {
        $warehouses = Warehouse::withoutGlobalScopes()
            ->where('organization_id', $orgId)
            ->get();

        return response()->json([
            'data' => $warehouses,
            'meta' => ['total' => $warehouses->count()],
            'errors' => [],
        ]);
    }

    /**
     * Create warehouse.
     */
    public function storeWarehouse(Request $request, string $orgId): JsonResponse
    {
        $organization = Organization::findOrFail($orgId);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:100'],
            'city' => ['nullable', 'string'],
            'address' => ['nullable', 'string'],
        ]);

        $warehouse = $this->inventoryService->createWarehouse($organization, $validated);

        return response()->json([
            'data' => $warehouse,
            'meta' => ['message' => 'Warehouse created successfully.'],
            'errors' => [],
        ], 201);
    }

    /**
     * Record stock receipt.
     */
    public function receiveStock(Request $request, string $orgId): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => ['required', 'uuid'],
            'warehouse_id' => ['required', 'uuid'],
            'quantity' => ['required', 'numeric', 'min:0.0001'],
            'unit_cost' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
        ]);

        $product = Product::withoutGlobalScopes()->where('organization_id', $orgId)->findOrFail($validated['product_id']);
        $warehouse = Warehouse::withoutGlobalScopes()->where('organization_id', $orgId)->findOrFail($validated['warehouse_id']);

        $movement = $this->inventoryService->recordStockReceipt(
            $product,
            $warehouse,
            (float) $validated['quantity'],
            (float) $validated['unit_cost'],
            'manual_receipt',
            null,
            $request->user(),
            $validated['notes'] ?? null
        );

        return response()->json([
            'data' => $movement,
            'meta' => ['message' => 'Stock received successfully.'],
            'errors' => [],
        ], 201);
    }

    /**
     * Adjust stock.
     */
    public function adjustStock(Request $request, string $orgId): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => ['required', 'uuid'],
            'warehouse_id' => ['required', 'uuid'],
            'new_quantity' => ['required', 'numeric', 'min:0'],
            'unit_cost' => ['nullable', 'numeric', 'min:0'],
            'reason' => ['nullable', 'string'],
        ]);

        $product = Product::withoutGlobalScopes()->where('organization_id', $orgId)->findOrFail($validated['product_id']);
        $warehouse = Warehouse::withoutGlobalScopes()->where('organization_id', $orgId)->findOrFail($validated['warehouse_id']);

        $movement = $this->inventoryService->adjustStock(
            $product,
            $warehouse,
            (float) $validated['new_quantity'],
            isset($validated['unit_cost']) ? (float) $validated['unit_cost'] : null,
            $validated['reason'] ?? 'Manual stock count adjustment',
            $request->user()
        );

        return response()->json([
            'data' => $movement,
            'meta' => ['message' => 'Stock adjusted successfully.'],
            'errors' => [],
        ]);
    }

    /**
     * Transfer stock between warehouses.
     */
    public function transferStock(Request $request, string $orgId): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => ['required', 'uuid'],
            'source_warehouse_id' => ['required', 'uuid'],
            'target_warehouse_id' => ['required', 'uuid'],
            'quantity' => ['required', 'numeric', 'min:0.0001'],
        ]);

        $product = Product::withoutGlobalScopes()->where('organization_id', $orgId)->findOrFail($validated['product_id']);
        $source = Warehouse::withoutGlobalScopes()->where('organization_id', $orgId)->findOrFail($validated['source_warehouse_id']);
        $target = Warehouse::withoutGlobalScopes()->where('organization_id', $orgId)->findOrFail($validated['target_warehouse_id']);

        $result = $this->inventoryService->transferStock(
            $product,
            $source,
            $target,
            (float) $validated['quantity'],
            $request->user()
        );

        return response()->json([
            'data' => $result,
            'meta' => ['message' => 'Stock transferred successfully.'],
            'errors' => [],
        ]);
    }

    /**
     * Get inventory valuation report.
     */
    public function valuationReport(Request $request, string $orgId): JsonResponse
    {
        $organization = Organization::findOrFail($orgId);

        $warehouseId = $request->query('warehouse_id');
        $report = $this->inventoryService->getValuationReport($organization, $warehouseId);

        return response()->json([
            'data' => $report,
            'meta' => ['organization_id' => $orgId],
            'errors' => [],
        ]);
    }

    /**
     * Process automated Cost of Goods Sold (COGS) for a sales invoice.
     */
    public function processInvoiceCogs(Request $request, string $orgId): JsonResponse
    {
        $validated = $request->validate([
            'sales_invoice_id' => ['required', 'uuid'],
            'warehouse_id' => ['required', 'uuid'],
        ]);

        $invoice = SalesInvoice::withoutGlobalScopes()
            ->where('organization_id', $orgId)
            ->findOrFail($validated['sales_invoice_id']);

        $warehouse = Warehouse::withoutGlobalScopes()
            ->where('organization_id', $orgId)
            ->findOrFail($validated['warehouse_id']);

        $result = $this->cogsEngine->processInvoiceCogs($invoice, $warehouse, $request->user());

        return response()->json([
            'data' => [
                'total_cogs' => $result['total_cogs'],
                'journal_entry_id' => $result['journal_entry']?->id,
                'journal_entry_number' => $result['journal_entry']?->entry_number,
                'dispatches' => $result['dispatches'],
            ],
            'meta' => ['message' => 'Automated COGS journal entry posted successfully.'],
            'errors' => [],
        ]);
    }
}
