<?php

namespace Tests\Feature;

use App\Domain\Accounting\ChartOfAccounts\Models\Account;
use App\Domain\Accounting\ChartOfAccounts\Templates\PakistanSmeChartTemplate;
use App\Domain\Accounting\Journal\Models\JournalEntry;
use App\Domain\Accounting\Period\Services\PeriodManager;
use App\Domain\Accounting\Posting\Services\PostingEngine;
use App\Domain\Accounting\Reconciliation\Services\SubledgerReconciliationService;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Database\Seeders\AccountTypeSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class SubledgerReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private User $user;
    private string $token;
    private PostingEngine $postingEngine;
    private SubledgerReconciliationService $reconciliationService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);
        $this->seed(AccountTypeSeeder::class);

        $this->user = User::factory()->create([
            'name' => 'Controller User',
            'email' => 'controller@distributors.pk',
        ]);
        $this->token = $this->user->createToken('test-token')->plainTextToken;

        $this->org = Organization::create([
            'name' => 'Subledger Test Org',
            'legal_name' => 'Subledger Test Org (Pvt) Ltd',
            'base_currency' => 'PKR',
            'fiscal_year_start_month' => 7,
        ]);
        $this->org->users()->attach($this->user->id, ['role' => 'owner', 'is_default' => true]);

        PakistanSmeChartTemplate::seedForOrganization($this->org);
        app(PeriodManager::class)->generateFiscalYear($this->org, 2025);

        $this->postingEngine = app(PostingEngine::class);
        $this->reconciliationService = app(SubledgerReconciliationService::class);
    }

    /**
     * P1-21: Test that duplicate reversals are strictly prevented.
     */
    public function test_duplicate_reversal_is_prevented(): void
    {
        $cash = Account::where('organization_id', $this->org->id)->where('code', '1010')->firstOrFail();
        $revenue = Account::where('organization_id', $this->org->id)->where('code', '4010')->firstOrFail();

        $entry = $this->postingEngine->createDraft($this->org, [
            'entry_date' => '2025-08-10',
            'description' => 'Original transaction',
            'lines' => [
                ['account_id' => $cash->id, 'debit' => 5000.0, 'credit' => 0.0],
                ['account_id' => $revenue->id, 'debit' => 0.0, 'credit' => 5000.0],
            ],
        ], $this->user);

        $posted = $this->postingEngine->postEntry($entry, $this->user);
        $this->assertTrue($posted->isPosted());

        // First reversal succeeds
        $reversal = $this->postingEngine->createReversal($posted, $this->user, 'Customer refund');
        $this->assertNotNull($reversal);
        $this->assertTrue($reversal->isPosted());
        $this->assertEquals($posted->id, $reversal->reversal_of_id);

        // Second reversal must throw InvalidArgumentException
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("already been reversed");
        $this->postingEngine->createReversal($posted, $this->user, 'Duplicate reversal attempt');
    }

    /**
     * P1-19 & P1-20: Test automated AR subledger reconciliation detects variances & direct manual bypasses.
     */
    public function test_ar_subledger_reconciliation_detects_matching_and_variances(): void
    {
        $arAccount = Account::where('organization_id', $this->org->id)->whereIn('code', ['1030', '1100'])->firstOrFail();
        $revenueAccount = Account::where('organization_id', $this->org->id)->where('code', '4010')->firstOrFail();

        // 1. Initial state: both zero -> reconciled
        $result = $this->reconciliationService->reconcileAccountsReceivable($this->org);
        $this->assertEquals($arAccount->code, $result['control_account_code']);
        $this->assertEquals('0.0000', $result['gl_balance']);
        $this->assertEquals('0.0000', $result['subledger_balance']);
        $this->assertEquals('0.0000', $result['variance']);
        $this->assertEquals('reconciled', $result['status']);
        $this->assertEquals(0, $result['direct_manual_postings_count']);

        // 2. Direct manual journal entry hitting control account (bypassing subledger)
        $period = \App\Domain\Accounting\Period\Models\AccountingPeriod::where('organization_id', $this->org->id)->firstOrFail();
        $manualEntry = JournalEntry::withoutGlobalScopes()->create([
            'organization_id' => $this->org->id,
            'accounting_period_id' => $period->id,
            'entry_number' => 'JE-2025-MANUAL-001',
            'entry_date' => '2025-08-15',
            'status' => 'posted',
            'source_type' => 'manual',
            'description' => 'Direct manual adjustment bypassing sales invoices',
            'currency' => 'PKR',
            'exchange_rate' => 1.000000,
            'total_amount' => 15000.0,
            'posted_at' => now(),
            'posted_by' => $this->user->id,
        ]);
        \App\Domain\Accounting\Journal\Models\JournalLine::withoutGlobalScopes()->create([
            'organization_id' => $this->org->id,
            'journal_entry_id' => $manualEntry->id,
            'account_id' => $arAccount->id,
            'line_number' => 1,
            'description' => 'Direct AR debit',
            'debit' => 15000.0,
            'credit' => 0.0,
            'currency' => 'PKR',
        ]);
        \App\Domain\Accounting\Journal\Models\JournalLine::withoutGlobalScopes()->create([
            'organization_id' => $this->org->id,
            'journal_entry_id' => $manualEntry->id,
            'account_id' => $revenueAccount->id,
            'line_number' => 2,
            'description' => 'Direct Revenue credit',
            'debit' => 0.0,
            'credit' => 15000.0,
            'currency' => 'PKR',
        ]);

        // 3. Reconcile AR: GL has 15000, Subledger has 0 -> discrepancy detected!
        $reconAfterManual = $this->reconciliationService->reconcileAccountsReceivable($this->org);
        $this->assertEquals('15000.0000', $reconAfterManual['gl_balance']);
        $this->assertEquals('0.0000', $reconAfterManual['subledger_balance']);
        $this->assertEquals('-15000.0000', $reconAfterManual['variance']);
        $this->assertEquals('discrepancy', $reconAfterManual['status']);
        $this->assertEquals(1, $reconAfterManual['direct_manual_postings_count']);
    }

    /**
     * P1-25: Test Inventory subledger reconciliation detects variances.
     */
    public function test_inventory_subledger_reconciliation(): void
    {
        // 1. Initial state: reconciled with 0 balance
        $initial = $this->reconciliationService->reconcileInventoryToGL($this->org);
        $this->assertEquals('reconciled', $initial['status']);
        $this->assertEquals('0.0000', $initial['subledger_balance']);
        $this->assertEquals('0.0000', $initial['gl_balance']);

        // 2. Add an inventory valuation layer without GL posting -> discrepancy
        $warehouse = \App\Domain\Inventory\Models\Warehouse::create([
            'organization_id' => $this->org->id,
            'name' => 'Main Warehouse',
            'code' => 'WH-01',
        ]);
        $product = \App\Domain\Inventory\Models\Product::create([
            'organization_id' => $this->org->id,
            'name' => 'Widget A',
            'sku' => 'WID-A',
            'cost_price' => 100.0,
            'selling_price' => 150.0,
        ]);
        \App\Domain\Inventory\Models\InventoryValuationLayer::create([
            'organization_id' => $this->org->id,
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity_received' => 10,
            'quantity_remaining' => 10,
            'unit_cost' => 100.0,
            'received_at' => now(),
        ]);

        $afterLayer = $this->reconciliationService->reconcileInventoryToGL($this->org);
        $this->assertEquals('1000.0000', $afterLayer['subledger_balance']);
        $this->assertEquals('0.0000', $afterLayer['gl_balance']);
        $this->assertEquals('1000.0000', $afterLayer['variance']);
        $this->assertEquals('discrepancy', $afterLayer['status']);
    }

    /**
     * P1-25: Test Revenue Recognition subledger reconciliation detects manual GL entries.
     */
    public function test_revenue_recognition_subledger_reconciliation(): void
    {
        $revGlAccount = Account::where('organization_id', $this->org->id)->whereIn('code', ['4010', '4000'])->firstOrFail();

        // 1. Initial state: reconciled
        $initial = $this->reconciliationService->reconcileRevenueRecognitionToGL($this->org);
        $this->assertEquals('reconciled', $initial['status']);

        // 2. Post a manual GL entry to 4010 without recognized schedule -> discrepancy
        $cash = Account::where('organization_id', $this->org->id)->where('code', '1010')->firstOrFail();
        $entry = $this->postingEngine->createDraft($this->org, [
            'entry_date' => '2025-08-10',
            'description' => 'Manual revenue bypass',
            'lines' => [
                ['account_id' => $cash->id, 'debit' => 2500.0, 'credit' => 0.0],
                ['account_id' => $revGlAccount->id, 'debit' => 0.0, 'credit' => 2500.0],
            ],
        ], $this->user);
        $this->postingEngine->postEntry($entry, $this->user);

        $afterManual = $this->reconciliationService->reconcileRevenueRecognitionToGL($this->org);
        $this->assertEquals('0.0000', $afterManual['subledger_balance']);
        $this->assertEquals('2500.0000', $afterManual['gl_balance']);
        $this->assertEquals('-2500.0000', $afterManual['variance']);
        $this->assertEquals('discrepancy', $afterManual['status']);
    }

    /**
     * P1-25: Test Fixed Assets subledger reconciliation detects active assets without GL capitalization.
     */
    public function test_fixed_assets_subledger_reconciliation(): void
    {
        $assetGlAccount = Account::where('organization_id', $this->org->id)->whereIn('code', ['1510', '1520', '1500'])->firstOrFail();
        $accumDepAccount = Account::where('organization_id', $this->org->id)->whereIn('code', ['1590'])->firstOrFail();
        $depExpAccount = Account::where('organization_id', $this->org->id)->whereIn('code', ['5010', '5020', '5090'])->first() ?? $assetGlAccount;

        // 1. Initial state: reconciled
        $initial = $this->reconciliationService->reconcileFixedAssetsToGL($this->org);
        $this->assertEquals('reconciled', $initial['status']);

        // 2. Create active fixed asset without GL entry -> discrepancy
        \App\Domain\Close\Models\FixedAsset::create([
            'organization_id' => $this->org->id,
            'asset_account_id' => $assetGlAccount->id,
            'accumulated_depreciation_account_id' => $accumDepAccount->id,
            'depreciation_expense_account_id' => $depExpAccount->id,
            'asset_number' => 'FA-2025-001',
            'name' => 'Office Laptop',
            'purchase_date' => '2025-08-01',
            'purchase_cost' => 120000.0,
            'salvage_value' => 10000.0,
            'useful_life_months' => 36,
            'monthly_depreciation' => 3055.55,
            'status' => 'active',
        ]);

        $afterAsset = $this->reconciliationService->reconcileFixedAssetsToGL($this->org);
        $this->assertEquals('120000.0000', $afterAsset['subledger_balance']);
        $this->assertEquals('0.0000', $afterAsset['gl_balance']);
        $this->assertEquals('120000.0000', $afterAsset['variance']);
        $this->assertEquals('discrepancy', $afterAsset['status']);
    }

    /**
     * P1-25: Test Tax subledger reconciliation.
     */
    public function test_tax_subledger_reconciliation(): void
    {
        $initial = $this->reconciliationService->reconcileTaxToGL($this->org);
        $this->assertEquals('reconciled', $initial['status']);
        $this->assertEquals('0.0000', $initial['subledger_balance']);
        $this->assertEquals('0.0000', $initial['gl_balance']);
    }

    /**
     * P1-17, P1-18: Test Bank Auto-Reconciliation with high confidence matching.
     */
    public function test_bank_auto_reconciliation(): void
    {
        $bankAccountModel = Account::where('organization_id', $this->org->id)->where('code', '1020')->firstOrFail();
        $revenue = Account::where('organization_id', $this->org->id)->where('code', '4010')->firstOrFail();

        $bankAccount = \App\Domain\Banking\Models\BankAccount::create([
            'organization_id' => $this->org->id,
            'account_id' => $bankAccountModel->id,
            'account_title' => 'Operating Bank Account',
            'account_number' => 'PK12MEZN00001234',
            'bank_name' => 'Meezan Bank',
            'currency' => 'PKR',
            'current_balance' => 0.0,
            'is_active' => true,
        ]);

        $tx = \App\Domain\Banking\Models\BankTransaction::create([
            'organization_id' => $this->org->id,
            'bank_account_id' => $bankAccount->id,
            'transaction_date' => '2025-08-10',
            'description' => 'Direct deposit from client',
            'reference' => 'REF-CLIENT-100',
            'type' => 'credit',
            'amount' => 50000.0,
            'balance_after' => 50000.0,
            'reconciliation_status' => 'unreconciled',
            'fingerprint' => 'fp-test-1234',
        ]);

        // Post exact matching journal entry: Debit Bank 50,000, Credit Revenue 50,000, Reference REF-CLIENT-100
        $entry = $this->postingEngine->createDraft($this->org, [
            'entry_date' => '2025-08-10',
            'description' => 'Client invoice payment REF-CLIENT-100',
            'lines' => [
                ['account_id' => $bankAccountModel->id, 'debit' => 50000.0, 'credit' => 0.0],
                ['account_id' => $revenue->id, 'debit' => 0.0, 'credit' => 50000.0],
            ],
        ], $this->user);
        $posted = $this->postingEngine->postEntry($entry, $this->user);

        // Run auto-reconciliation API
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson("/api/v1/organizations/{$this->org->id}/bank-accounts/{$bankAccount->id}/auto-reconcile", [
                'min_confidence' => 0.90,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.auto_reconciled_count', 1);

        $tx->refresh();
        $this->assertEquals('reconciled', $tx->reconciliation_status);
        $this->assertEquals($posted->id, $tx->matched_journal_entry_id);
    }

    /**
     * P1-25: Test CLI command and API endpoint for subledger reconciliation covering all 7 subledger domains.
     */
    public function test_reconciliation_cli_and_api(): void
    {
        // Test Artisan command
        $this->artisan('reconciliation:subledger', [
            'organization_id' => $this->org->id,
        ])->assertExitCode(0);

        // Test API endpoint
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson("/api/v1/organizations/{$this->org->id}/reconciliations/subledger");

        $response->assertStatus(200)
            ->assertJsonPath('data.organization_id', $this->org->id)
            ->assertJsonStructure([
                'data' => [
                    'organization_id',
                    'as_of_date',
                    'overall_status',
                    'discrepancy_count',
                    'actionable_recommendations',
                    'reconciliations' => [
                        'accounts_receivable' => ['control_account_code', 'gl_balance', 'subledger_balance', 'variance', 'status'],
                        'accounts_payable' => ['control_account_code', 'gl_balance', 'subledger_balance', 'variance', 'status'],
                        'bank_accounts' => ['control_account_code', 'gl_balance', 'subledger_balance', 'variance', 'status'],
                        'inventory' => ['control_account_code', 'gl_balance', 'subledger_balance', 'variance', 'status'],
                        'revenue_recognition' => ['control_account_code', 'gl_balance', 'subledger_balance', 'variance', 'status'],
                        'fixed_assets' => ['control_account_code', 'gl_balance', 'subledger_balance', 'variance', 'status'],
                        'tax' => ['control_account_code', 'gl_balance', 'subledger_balance', 'variance', 'status'],
                    ],
                    'generated_at',
                ],
            ]);
    }
}

