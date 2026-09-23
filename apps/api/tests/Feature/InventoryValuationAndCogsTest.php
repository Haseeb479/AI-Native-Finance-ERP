<?php

namespace Tests\Feature;

use App\Domain\Accounting\ChartOfAccounts\Models\Account;
use App\Domain\Accounting\ChartOfAccounts\Templates\PakistanSmeChartTemplate;
use App\Domain\Accounting\Period\Services\PeriodManager;
use App\Domain\Inventory\Models\Product;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\Models\WarehouseStock;
use App\Domain\Inventory\Services\CogsEngine;
use App\Domain\Inventory\Services\InventoryService;
use App\Domain\Organization\Models\Organization;
use App\Domain\Sales\Models\Customer;
use App\Domain\Sales\Models\SalesInvoice;
use App\Domain\Sales\Services\InvoiceService;
use App\Models\User;
use Database\Seeders\AccountTypeSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryValuationAndCogsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Organization $org;
    private string $ownerToken;
    private Warehouse $warehouse;
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);
        $this->seed(AccountTypeSeeder::class);

        $this->owner = User::factory()->create([
            'name' => 'Inventory Manager Babar',
            'email' => 'inventory@acme.pk',
        ]);
        $this->ownerToken = $this->owner->createToken('owner')->plainTextToken;

        $this->org = Organization::create([
            'name' => 'Apex Manufacturing PK',
            'legal_name' => 'Apex Manufacturing Private Limited',
            'base_currency' => 'PKR',
            'fiscal_year_start_month' => 7,
        ]);
        $this->org->users()->attach($this->owner->id, ['role' => 'owner', 'is_default' => true]);

        PakistanSmeChartTemplate::seedForOrganization($this->org);
        app(PeriodManager::class)->generateFiscalYear($this->org, 2025);

        $this->warehouse = Warehouse::create([
            'organization_id' => $this->org->id,
            'code' => 'WH-KHI-MAIN',
            'name' => 'Karachi Central Warehouse',
            'city' => 'Karachi',
            'is_active' => true,
        ]);

        $this->customer = Customer::create([
            'organization_id' => $this->org->id,
            'name' => 'Textile Mills Ltd',
            'email' => 'finance@textile.pk',
            'currency' => 'PKR',
            'payment_terms_days' => 30,
        ]);
    }

    public function test_can_create_category_warehouse_and_products(): void
    {
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->ownerToken}",
            'X-Organization-Id' => $this->org->id,
        ])->postJson("/api/v1/organizations/{$this->org->id}/inventory/products", [
            'sku' => 'MOTOR-AC-10HP',
            'name' => 'Heavy Industrial 10HP Induction Motor',
            'unit_of_measure' => 'pcs',
            'valuation_method' => 'weighted_average',
            'cost_price' => 45000.00,
            'selling_price' => 60000.00,
            'reorder_level' => 5,
        ]);

        $response->assertStatus(201);
        $this->assertEquals('MOTOR-AC-10HP', $response->json('data.sku'));
        $this->assertEquals('weighted_average', $response->json('data.valuation_method'));
    }

    public function test_weighted_average_stock_receipt_and_dispatch(): void
    {
        $invService = app(InventoryService::class);

        $product = $invService->createProduct($this->org, [
            'sku' => 'PUMP-CENTRIFUGAL-50MM',
            'name' => '50mm High-Flow Centrifugal Pump',
            'valuation_method' => 'weighted_average',
        ]);

        // Batch 1: Receive 10 units @ 10,000 = 100,000 total (WAC: 10,000)
        $invService->recordStockReceipt($product, $this->warehouse, 10, 10000.00, 'po_receipt');

        $stock = WarehouseStock::where('product_id', $product->id)->first();
        $this->assertEquals(10.0, (float) $stock->quantity_on_hand);
        $this->assertEquals(10000.00, (float) $stock->weighted_average_cost);
        $this->assertEquals(100000.00, (float) $stock->total_valuation);

        // Batch 2: Receive 10 units @ 12,000 = 120,000 total
        // Total: 20 units, Total value: 220,000 -> New WAC = 11,000
        $invService->recordStockReceipt($product, $this->warehouse, 10, 12000.00, 'po_receipt');

        $stock->refresh();
        $this->assertEquals(20.0, (float) $stock->quantity_on_hand);
        $this->assertEquals(11000.00, (float) $stock->weighted_average_cost);
        $this->assertEquals(220000.00, (float) $stock->total_valuation);

        // Dispatch 5 units -> COGS should be 5 * 11,000 = 55,000
        $dispatch = $invService->recordStockDispatch($product, $this->warehouse, 5, 'sales_invoice');

        $this->assertEquals(55000.00, $dispatch['total_cost']);
        $this->assertEquals(11000.00, $dispatch['unit_cost']);

        $stock->refresh();
        $this->assertEquals(15.0, (float) $stock->quantity_on_hand);
        $this->assertEquals(165000.00, (float) $stock->total_valuation);
    }

    public function test_fifo_valuation_layer_consumption(): void
    {
        $invService = app(InventoryService::class);

        $product = $invService->createProduct($this->org, [
            'sku' => 'COPPER-CABLE-16MM',
            'name' => '16mm 4-Core Armored Copper Cable',
            'valuation_method' => 'fifo',
        ]);

        // Layer 1: 100 meters @ 500 PKR = 50,000
        $invService->recordStockReceipt($product, $this->warehouse, 100, 500.00);

        // Layer 2: 100 meters @ 600 PKR = 60,000
        $invService->recordStockReceipt($product, $this->warehouse, 100, 600.00);

        // Dispatch 150 meters:
        // FIFO must consume 100 meters from Layer 1 (@ 500 = 50,000)
        // and 50 meters from Layer 2 (@ 600 = 30,000)
        // Expected total COGS = 80,000
        $dispatch = $invService->recordStockDispatch($product, $this->warehouse, 150, 'sales_invoice');

        $this->assertEquals(80000.00, $dispatch['total_cost']);
        $this->assertEquals(533.3333, round($dispatch['unit_cost'], 4));

        $stock = WarehouseStock::where('product_id', $product->id)->first();
        $this->assertEquals(50.0, (float) $stock->quantity_on_hand);
        $this->assertEquals(30000.00, (float) $stock->total_valuation);
    }

    public function test_stock_adjustment_and_inter_warehouse_transfer(): void
    {
        $invService = app(InventoryService::class);

        $whLahore = Warehouse::create([
            'organization_id' => $this->org->id,
            'code' => 'WH-LHE-01',
            'name' => 'Lahore Distribution Hub',
            'city' => 'Lahore',
        ]);

        $product = $invService->createProduct($this->org, [
            'sku' => 'DRILL-CHUCK-HD',
            'name' => 'Heavy Duty 13mm Drill Chuck',
            'cost_price' => 1500.00,
        ]);

        // Stock in Karachi: 20 units
        $invService->recordStockReceipt($product, $this->warehouse, 20, 1500.00);

        // Transfer 8 units to Lahore
        $transfer = $invService->transferStock($product, $this->warehouse, $whLahore, 8, $this->owner);

        $this->assertEquals(8.0, $transfer['transferred_quantity']);
        $this->assertEquals(1500.00, $transfer['unit_cost']);

        $stockKarachi = WarehouseStock::where('product_id', $product->id)->where('warehouse_id', $this->warehouse->id)->first();
        $stockLahore = WarehouseStock::where('product_id', $product->id)->where('warehouse_id', $whLahore->id)->first();

        $this->assertEquals(12.0, (float) $stockKarachi->quantity_on_hand);
        $this->assertEquals(8.0, (float) $stockLahore->quantity_on_hand);

        // Physical Audit Adjustment in Lahore: found 7 units instead of 8
        $adjustment = $invService->adjustStock($product, $whLahore, 7.0, 1500.00, 'Damaged unit scrapped', $this->owner);

        $this->assertEquals(-1.0, (float) $adjustment->quantity);
        $this->assertEquals(7.0, (float) $stockLahore->fresh()->quantity_on_hand);
    }

    public function test_automated_cogs_engine_dispatches_stock_and_posts_balanced_gl_entry(): void
    {
        $invService = app(InventoryService::class);
        $cogsEngine = app(CogsEngine::class);
        $invoiceService = app(InvoiceService::class);

        $product = $invService->createProduct($this->org, [
            'sku' => 'VALVE-SOLENOID-24V',
            'name' => '24V DC Brass Solenoid Valve',
            'valuation_method' => 'weighted_average',
        ]);

        // Receive 50 units @ 2,000 = 100,000
        $invService->recordStockReceipt($product, $this->warehouse, 50, 2000.00);

        // Create Sales Invoice for 10 units @ 3,500 selling price = 35,000 Revenue
        $revenueAccount = Account::where('organization_id', $this->org->id)->where('code', '4010')->first();

        $invoice = $invoiceService->createInvoice($this->org, [
            'customer_id' => $this->customer->id,
            'issue_date' => '2025-08-20',
            'lines' => [
                [
                    'revenue_account_id' => $revenueAccount->id,
                    'product_id' => $product->id,
                    'warehouse_id' => $this->warehouse->id,
                    'description' => $product->name,
                    'quantity' => 10,
                    'unit_price' => 3500.00,
                    'tax_rate' => 0.0,
                ],
            ],
        ], $this->owner);

        // Post the Sales Invoice (Posts AR vs Revenue journal)
        $invoiceService->postInvoice($invoice, $this->owner);
        $this->assertEquals('sent', $invoice->fresh()->status);

        // Process Automated COGS Engine
        // COGS should be 10 units * 2,000 cost = 20,000 PKR
        $cogsResult = $cogsEngine->processInvoiceCogs($invoice, $this->warehouse, $this->owner);

        $this->assertEquals(20000.00, (float) $cogsResult['total_cogs']);
        $this->assertNotNull($cogsResult['journal_entry']);

        $journal = $cogsResult['journal_entry']->fresh(['lines.account']);
        $this->assertEquals('posted', $journal->status);
        $this->assertEquals(20000.00, (float) $journal->total_amount);

        // Verify General Ledger Balancing Invariant: Debit == Credit
        $totalDebit = $journal->lines->sum('debit');
        $totalCredit = $journal->lines->sum('credit');
        $this->assertEquals(20000.00, round($totalDebit, 4));
        $this->assertEquals(20000.00, round($totalCredit, 4));

        // Check Account mapping: Debit 5010 (COGS), Credit 1070 (Inventory)
        $debitLine = $journal->lines->firstWhere('debit', '>', 0);
        $creditLine = $journal->lines->firstWhere('credit', '>', 0);

        $this->assertEquals('5010', $debitLine->account->code);
        $this->assertEquals('1070', $creditLine->account->code);

        // Check Inventory on hand: 50 - 10 = 40 units remaining
        $stock = WarehouseStock::where('product_id', $product->id)->first();
        $this->assertEquals(40.0, (float) $stock->quantity_on_hand);
        $this->assertEquals(80000.00, (float) $stock->total_valuation);
    }
}
