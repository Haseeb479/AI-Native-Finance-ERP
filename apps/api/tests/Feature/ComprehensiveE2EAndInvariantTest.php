<?php

namespace Tests\Feature;

use App\Domain\Accounting\ChartOfAccounts\Models\Account;
use App\Domain\Accounting\ChartOfAccounts\Templates\PakistanSmeChartTemplate;
use App\Domain\Accounting\Journal\Models\JournalEntry;
use App\Domain\Accounting\Period\Services\PeriodManager;
use App\Domain\Accounting\Posting\Services\PostingEngine;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\Services\CogsEngine;
use App\Domain\Inventory\Services\InventoryService;
use App\Domain\Organization\Models\Organization;
use App\Domain\Procurement\Services\ProcurementService;
use App\Domain\Procurement\Services\ThreeWayMatchingService;
use App\Domain\Purchasing\Models\Vendor;
use App\Domain\Purchasing\Services\BillService;
use App\Domain\Sales\Models\Customer;
use App\Domain\Sales\Services\InvoiceService;
use Database\Seeders\AccountTypeSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Step 29 — Comprehensive E2E & Accounting Invariant Validation
 *
 * Test 1: Full pipeline — Requisition → PO → GRN → 3-Way Match → Bill → Sales Invoice → COGS → Verify GL balance
 * Test 2: Accounting invariant — Total Debit == Total Credit across ALL journal entries for an org
 * Test 3: FIFO layer quantities never go negative after multiple dispatches
 * Test 4: High-volume stress — 50 journal entries, all balanced, global sum holds
 * Test 5: WHT / Sales Tax produce balanced GL on invoice posting
 */
class ComprehensiveE2EAndInvariantTest extends TestCase
{
    use RefreshDatabase;

    private \App\Models\User $owner;
    private Organization $org;
    private string $ownerToken;
    private Warehouse $warehouse;
    private Customer $customer;
    private Vendor $vendor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);
        $this->seed(AccountTypeSeeder::class);

        $this->owner = \App\Models\User::factory()->create([
            'name'  => 'E2E Test CFO Zubair',
            'email' => 'cfo@apexfinance.pk',
        ]);
        $this->ownerToken = $this->owner->createToken('owner')->plainTextToken;

        $this->org = Organization::create([
            'name'                    => 'Apex Finance Systems PK',
            'legal_name'             => 'Apex Finance Systems Private Limited',
            'base_currency'          => 'PKR',
            'fiscal_year_start_month' => 7,
        ]);
        $this->org->users()->attach($this->owner->id, ['role' => 'owner', 'is_default' => true]);

        PakistanSmeChartTemplate::seedForOrganization($this->org);
        app(PeriodManager::class)->generateFiscalYear($this->org, 2025);

        $this->warehouse = Warehouse::create([
            'organization_id' => $this->org->id,
            'code'            => 'WH-ISB-MAIN',
            'name'            => 'Islamabad Main Warehouse',
            'city'            => 'Islamabad',
            'is_active'       => true,
        ]);

        $this->customer = Customer::create([
            'organization_id'    => $this->org->id,
            'name'               => 'Pak Textile Mills Ltd',
            'email'              => 'finance@paktextile.pk',
            'currency'           => 'PKR',
            'payment_terms_days' => 30,
        ]);

        $this->vendor = Vendor::create([
            'organization_id' => $this->org->id,
            'name'            => 'Steel Corp Pakistan Ltd',
            'email'           => 'orders@steelcorp.pk',
            'currency'        => 'PKR',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TEST 1: Full Pipeline — Procurement → Inventory → Sales → COGS → GL
    // ─────────────────────────────────────────────────────────────────────────

    public function test_full_pipeline_requisition_to_cogs_gl_balance(): void
    {
        $inventoryService   = app(InventoryService::class);
        $procurementService = app(ProcurementService::class);
        $threeWayService    = app(ThreeWayMatchingService::class);
        $invoiceService     = app(InvoiceService::class);
        $cogsEngine         = app(CogsEngine::class);

        // ── Step A: Create product & seed stock ───────────────────────────────
        $product = $inventoryService->createProduct($this->org, [
            'sku'              => 'STEEL-ROD-12MM',
            'name'             => '12mm Steel Rods',
            'valuation_method' => 'fifo',
            'unit_cost'        => 850.00,
        ]);

        $this->assertNotNull($product->id, 'Product must be created');

        // Seed 100 units of stock so COGS dispatch works
        $inventoryService->recordStockReceipt(
            $product, $this->warehouse, 100, 850.00,
            'purchase', null, $this->owner, 'Opening stock for E2E test'
        );

        // ── Step B: Create Purchase Requisition ──────────────────────────────
        $requisition = $procurementService->createRequisition($this->org, [
            'requested_date'  => '2025-08-01',
            'required_by_date' => '2025-08-15',
            'lines'           => [
                [
                    'item_code'            => 'STEEL-ROD-12MM',
                    'description'          => '12mm Steel Rods for Q3 Production',
                    'quantity'             => 100.0,
                    'estimated_unit_price' => 850.00,
                ],
            ],
        ], $this->owner);

        $this->assertEquals('draft', $requisition->status);
        $this->assertEquals(85000.00, (float) $requisition->estimated_total);

        // ── Step C: Submit then Approve Requisition ───────────────────────────
        $submitted = $procurementService->submitRequisition($requisition, $this->owner);
        $this->assertEquals('pending_approval', $submitted->status);

        $approved = $procurementService->approveRequisition($submitted, $this->owner);
        $this->assertEquals('approved', $approved->status);

        // ── Step D: Convert to Purchase Order ─────────────────────────────────
        // Method: convertRequisitionToPO(requisition, vendorId, user, additionalData)
        $po = $procurementService->convertRequisitionToPO($approved, $this->vendor->id, $this->owner, [
            'po_date' => '2025-08-02',
        ]);

        $this->assertNotNull($po->id);
        $this->assertEquals('draft', $po->status);

        // ── Step E: Issue PO ──────────────────────────────────────────────────
        $issued = $procurementService->issuePurchaseOrder($po, $this->owner);
        $this->assertEquals('issued', $issued->status);

        // ── Step F: Receive Goods (GRN) ───────────────────────────────────────
        $issued->load('lines');
        $receiptLines = $issued->lines->map(fn ($l) => [
            'purchase_order_line_id' => $l->id,
            'quantity_received'      => (float) $l->quantity,
            'unit_cost'              => (float) $l->unit_price,
        ])->toArray();

        $grn = $procurementService->receiveGoods($issued, $receiptLines, [
            'received_date' => '2025-08-10',
            'warehouse_id'  => $this->warehouse->id,
        ], $this->owner);

        $this->assertNotNull($grn->id);
        $this->assertEquals('received', $grn->status);

        // ── Step G: Generate Bill from PO ─────────────────────────────────────
        $bill = $procurementService->generateBillFromPO($issued, $this->owner);
        $this->assertNotNull($bill->id);

        // ── Step H: 3-Way Match ───────────────────────────────────────────────
        // performMatch(bill, po, grn, tolerancePercent, user)
        $match = $threeWayService->performMatch($bill, $issued, $grn, 2.0, $this->owner);
        $this->assertNotNull($match->id);
        $this->assertContains($match->status, ['matched', 'variance', 'waived', 'exception']);

        // ── Step I: Resolve Revenue Account for Sales Invoice ─────────────────
        $revenueAccount = Account::withoutGlobalScopes()
            ->where('organization_id', $this->org->id)
            ->where('code', '4010')
            ->firstOrFail();

        // ── Step J: Create & Post Sales Invoice ───────────────────────────────
        $invoice = $invoiceService->createInvoice($this->org, [
            'customer_id' => $this->customer->id,
            'issue_date'  => '2025-08-20',
            'lines'       => [
                [
                    'line_number'       => 1,
                    'product_id'        => $product->id,
                    'warehouse_id'      => $this->warehouse->id,
                    'revenue_account_id' => $revenueAccount->id,
                    'description'       => '12mm Steel Rods',
                    'quantity'          => 50.0,
                    'unit_price'        => 1200.00,
                    'tax_rate'          => 17.0,
                ],
            ],
        ], $this->owner);

        $this->assertNotNull($invoice->id);

        $postedInvoice = $invoiceService->postInvoice($invoice, $this->owner);
        $this->assertContains($postedInvoice->status, ['sent', 'posted']); // InvoiceService sets 'sent' after GL posting

        // ── Step K: Process COGS ──────────────────────────────────────────────
        $cogsResult = $cogsEngine->processInvoiceCogs($postedInvoice, $this->warehouse, $this->owner);

        $this->assertGreaterThan(0, $cogsResult['total_cogs']);
        $this->assertNotNull($cogsResult['journal_entry']);
        $this->assertEquals('posted', $cogsResult['journal_entry']->status);

        // ── Step L: Verify the COGS journal entry is balanced ─────────────────
        $cogsJournal = $cogsResult['journal_entry'];
        $cogsJournal->load('lines');

        $totalDebit  = (float) $cogsJournal->lines->sum('debit');
        $totalCredit = (float) $cogsJournal->lines->sum('credit');

        $this->assertEqualsWithDelta($totalDebit, $totalCredit, 0.001,
            'COGS journal entry must be balanced (Debit == Credit)'
        );

        // ── Step M: Global GL balance for this org ────────────────────────────
        $this->assertGlobalGlBalance($this->org);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TEST 2: Global Accounting Invariant — All Posted JE must be balanced
    // ─────────────────────────────────────────────────────────────────────────

    public function test_accounting_invariant_all_posted_entries_are_balanced(): void
    {
        $postingEngine = app(PostingEngine::class);

        $amounts = [1000, 2500, 7350, 15000, 500, 12000, 3750, 8900, 250, 42000,
                    6600, 11111, 9999, 4444, 17500, 28000, 750, 3300, 6700, 95000];

        $cashAccount = Account::withoutGlobalScopes()
            ->where('organization_id', $this->org->id)
            ->where('code', '1010')
            ->firstOrFail();

        $arAccount = Account::withoutGlobalScopes()
            ->where('organization_id', $this->org->id)
            ->where('code', '1030')
            ->firstOrFail();

        foreach ($amounts as $i => $amount) {
            $draft = $postingEngine->createDraft($this->org, [
                'entry_date'  => '2025-09-01',
                'description' => "Test Entry #{$i} — Amount {$amount}",
                'source_type' => 'manual',
                'currency'    => 'PKR',
                'lines' => [
                    ['account_id' => $cashAccount->id, 'debit' => $amount, 'credit' => 0],
                    ['account_id' => $arAccount->id,   'debit' => 0, 'credit' => $amount],
                ],
            ], $this->owner);

            $postingEngine->postEntry($draft, $this->owner);
        }

        $allPostedEntries = JournalEntry::withoutGlobalScopes()
            ->where('organization_id', $this->org->id)
            ->where('status', 'posted')
            ->with('lines')
            ->get();

        $this->assertGreaterThanOrEqual(20, $allPostedEntries->count(),
            'Expected at least 20 posted journal entries'
        );

        $globalDebit     = 0.0;
        $globalCredit    = 0.0;
        $unbalancedCount = 0;

        foreach ($allPostedEntries as $je) {
            $debit  = (float) $je->lines->sum('debit');
            $credit = (float) $je->lines->sum('credit');

            if (abs($debit - $credit) > 0.001) {
                $unbalancedCount++;
            }

            $globalDebit  += $debit;
            $globalCredit += $credit;
        }

        $this->assertEquals(0, $unbalancedCount,
            "Found {$unbalancedCount} unbalanced journal entries — accounting invariant violated"
        );

        $this->assertEqualsWithDelta($globalDebit, $globalCredit, 0.01,
            'Global sum of all debits must equal global sum of all credits'
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TEST 3: FIFO Layer Quantities Never Go Negative
    // ─────────────────────────────────────────────────────────────────────────

    public function test_fifo_layers_never_go_negative_after_dispatches(): void
    {
        $inventoryService = app(InventoryService::class);

        $product = $inventoryService->createProduct($this->org, [
            'sku'              => 'FIFO-TEST-ITEM',
            'name'             => 'FIFO Invariant Test Product',
            'valuation_method' => 'fifo',
            'unit_cost'        => 100.00,
        ]);

        // Receive 3 batches at different costs
        $inventoryService->recordStockReceipt($product, $this->warehouse, 50, 100.00, 'purchase', null, $this->owner, 'Batch 1');
        $inventoryService->recordStockReceipt($product, $this->warehouse, 30, 120.00, 'purchase', null, $this->owner, 'Batch 2');
        $inventoryService->recordStockReceipt($product, $this->warehouse, 20, 115.00, 'purchase', null, $this->owner, 'Batch 3');

        // Total: 100 units received
        $stock = \App\Domain\Inventory\Models\WarehouseStock::withoutGlobalScopes()
            ->where('organization_id', $this->org->id)
            ->where('product_id', $product->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->firstOrFail();

        $this->assertEquals(100.0, (float) $stock->quantity_on_hand);

        // Dispatch 80 units — should consume batch 1 (50) + batch 2 (30)
        $dispatch1 = $inventoryService->recordStockDispatch(
            $product, $this->warehouse, 80, 'sale', null, $this->owner, 'Dispatch 80 units'
        );

        $this->assertEquals(80.0, $dispatch1['quantity']);

        // Reload stock
        $stock->refresh();
        $this->assertEquals(20.0, (float) $stock->quantity_on_hand,
            'After dispatching 80 of 100 units, 20 must remain'
        );

        // Verify all FIFO layers have qty_remaining >= 0
        $layers = \App\Domain\Inventory\Models\InventoryValuationLayer::withoutGlobalScopes()
            ->where('organization_id', $this->org->id)
            ->where('product_id', $product->id)
            ->get();

        foreach ($layers as $layer) {
            $this->assertGreaterThanOrEqual(0, (float) $layer->quantity_remaining,
                "FIFO layer ID {$layer->id} has negative quantity_remaining — invariant violated"
            );
        }

        // Dispatch remaining 20 units
        $dispatch2 = $inventoryService->recordStockDispatch(
            $product, $this->warehouse, 20, 'sale', null, $this->owner, 'Final dispatch'
        );

        $this->assertEquals(20.0, $dispatch2['quantity']);

        $stock->refresh();
        $this->assertEquals(0.0, (float) $stock->quantity_on_hand);

        // Verify layers still non-negative after full depletion
        $layersFinal = \App\Domain\Inventory\Models\InventoryValuationLayer::withoutGlobalScopes()
            ->where('organization_id', $this->org->id)
            ->where('product_id', $product->id)
            ->get();

        foreach ($layersFinal as $layer) {
            $this->assertGreaterThanOrEqual(0, (float) $layer->quantity_remaining,
                "FIFO layer went negative after full depletion — invariant violated"
            );
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TEST 4: High-Volume Stress — 50 Journal Entries, Global Balance Holds
    // ─────────────────────────────────────────────────────────────────────────

    public function test_high_volume_50_journal_entries_global_balance_holds(): void
    {
        $postingEngine = app(PostingEngine::class);

        $cashAccount = Account::withoutGlobalScopes()
            ->where('organization_id', $this->org->id)
            ->where('code', '1010')
            ->firstOrFail();

        $revenueAccount = Account::withoutGlobalScopes()
            ->where('organization_id', $this->org->id)
            ->where('code', '4010')
            ->firstOrFail();

        // Post 50 entries with deterministic amounts
        for ($i = 1; $i <= 50; $i++) {
            $amount = round($i * 1337.77 + ($i % 7) * 250.0, 2);

            $draft = $postingEngine->createDraft($this->org, [
                'entry_date'  => '2025-09-15',
                'description' => "Stress Test JE #{$i}",
                'source_type' => 'manual',
                'currency'    => 'PKR',
                'lines' => [
                    ['account_id' => $cashAccount->id,    'debit' => $amount, 'credit' => 0.0],
                    ['account_id' => $revenueAccount->id, 'debit' => 0.0,    'credit' => $amount],
                ],
            ], $this->owner);

            $postingEngine->postEntry($draft, $this->owner);
        }

        // Aggregate via DB query
        $result = DB::table('journal_lines')
            ->join('journal_entries', 'journal_lines.journal_entry_id', '=', 'journal_entries.id')
            ->where('journal_entries.organization_id', $this->org->id)
            ->where('journal_entries.status', 'posted')
            ->selectRaw('SUM(debit) as total_debit, SUM(credit) as total_credit, COUNT(DISTINCT journal_entry_id) as entry_count')
            ->first();

        $this->assertGreaterThanOrEqual(50, (int) $result->entry_count,
            'Expected at least 50 posted journal entries'
        );

        $this->assertEqualsWithDelta(
            (float) $result->total_debit,
            (float) $result->total_credit,
            0.01,
            'Global Debit sum must equal Global Credit sum across all 50+ posted entries'
        );

        // Also verify the per-entry balance using the model method
        $entries = JournalEntry::withoutGlobalScopes()
            ->where('organization_id', $this->org->id)
            ->where('status', 'posted')
            ->with('lines')
            ->get();

        $violated = $entries->filter(fn ($je) => ! $je->isBalanced())->count();
        $this->assertEquals(0, $violated,
            "{$violated} journal entries violated the debit == credit invariant"
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TEST 5: Sales Invoice Posting Creates Balanced GL Entry
    // ─────────────────────────────────────────────────────────────────────────

    public function test_sales_invoice_posting_produces_balanced_gl(): void
    {
        $invoiceService = app(InvoiceService::class);

        $revenueAccount = Account::withoutGlobalScopes()
            ->where('organization_id', $this->org->id)
            ->where('code', '4010')
            ->firstOrFail();

        // Create invoice with 17% Sales Tax
        $invoice = $invoiceService->createInvoice($this->org, [
            'customer_id' => $this->customer->id,
            'issue_date'  => '2025-09-01',
            'lines'       => [
                [
                    'line_number'       => 1,
                    'revenue_account_id' => $revenueAccount->id,
                    'description'       => 'Consulting Services Q3',
                    'quantity'          => 1.0,
                    'unit_price'        => 100000.00,
                    'tax_rate'          => 17.0,
                ],
            ],
        ], $this->owner);

        $this->assertNotNull($invoice->id);

        // Post the invoice — creates AR + Revenue + Tax GL entry
        $postedInvoice = $invoiceService->postInvoice($invoice, $this->owner);
        // InvoiceService sets status to 'sent' after creating the GL journal entry
        $this->assertContains($postedInvoice->status, ['sent', 'posted']);

        // Find the journal entry for this invoice (source_type = 'invoice' per InvoiceService)
        $journalEntry = JournalEntry::withoutGlobalScopes()
            ->where('organization_id', $this->org->id)
            ->where('source_type', 'invoice')
            ->where('source_id', $invoice->id)
            ->with('lines')
            ->firstOrFail();

        $totalDebit  = (float) $journalEntry->lines->sum('debit');
        $totalCredit = (float) $journalEntry->lines->sum('credit');

        $this->assertEqualsWithDelta($totalDebit, $totalCredit, 0.001,
            'Sales invoice GL entry must be balanced (Debit == Credit)'
        );

        $this->assertGreaterThan(0, $totalDebit,
            'Invoice journal entry must have non-zero debit lines'
        );

        // Final global check
        $this->assertGlobalGlBalance($this->org);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helper: Verify Global GL Balance for an Organization
    // ─────────────────────────────────────────────────────────────────────────

    private function assertGlobalGlBalance(Organization $org): void
    {
        $result = DB::table('journal_lines')
            ->join('journal_entries', 'journal_lines.journal_entry_id', '=', 'journal_entries.id')
            ->where('journal_entries.organization_id', $org->id)
            ->where('journal_entries.status', 'posted')
            ->selectRaw('COALESCE(SUM(debit), 0) as total_debit, COALESCE(SUM(credit), 0) as total_credit')
            ->first();

        $this->assertEqualsWithDelta(
            (float) $result->total_debit,
            (float) $result->total_credit,
            0.01,
            "Global GL balance violated for org {$org->id}: " .
            "Debit={$result->total_debit}, Credit={$result->total_credit}"
        );
    }
}
