<?php

namespace Tests\Feature;

use App\Domain\Accounting\ChartOfAccounts\Models\Account;
use App\Domain\Accounting\ChartOfAccounts\Templates\PakistanSmeChartTemplate;
use App\Domain\Accounting\Period\Services\PeriodManager;
use App\Domain\Accounting\Posting\Services\PostingEngine;
use App\Domain\Organization\Models\Organization;
use App\Domain\Purchasing\Models\PurchaseBill;
use App\Domain\Purchasing\Models\Vendor;
use App\Domain\Sales\Models\Customer;
use App\Domain\Sales\Models\SalesInvoice;
use App\Models\User;
use Database\Seeders\AccountTypeSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportingTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Organization $org;
    private string $token;
    private PostingEngine $postingEngine;

    // Commonly used account codes from Pakistan SME COA
    private Account $cashAccount;       // 1010 - Asset, debit normal
    private Account $arAccount;         // 1030 - Asset, debit normal
    private Account $apAccount;         // 2010 - Liability, credit normal
    private Account $equityAccount;     // 3010 - Equity, credit normal
    private Account $revenueAccount;    // 4010 - Revenue, credit normal
    private Account $cogsAccount;       // 5010 - Expense (COGS group), debit normal
    private Account $expenseAccount;    // 6020 - Expense (Operating group), debit normal

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);
        $this->seed(AccountTypeSeeder::class);

        $this->owner = User::factory()->create([
            'name'  => 'Adeel Siddiqui',
            'email' => 'adeel@techventure.pk',
        ]);
        $this->token = $this->owner->createToken('test')->plainTextToken;

        $this->org = Organization::create([
            'name'                    => 'TechVenture (Pvt) Ltd',
            'legal_name'              => 'TechVenture Pakistan (Private) Limited',
            'base_currency'           => 'PKR',
            'fiscal_year_start_month' => 7,
        ]);
        $this->org->users()->attach($this->owner->id, ['role' => 'owner', 'is_default' => true]);

        PakistanSmeChartTemplate::seedForOrganization($this->org);
        app(PeriodManager::class)->generateFiscalYear($this->org, 2025);

        $this->postingEngine = app(PostingEngine::class);

        // Resolve commonly used accounts
        $this->cashAccount    = $this->account('1010');
        $this->arAccount      = $this->account('1030');
        $this->apAccount      = $this->account('2010');
        $this->equityAccount  = $this->account('3010');
        $this->revenueAccount = $this->account('4010');
        $this->cogsAccount    = $this->account('5010');
        $this->expenseAccount = $this->account('6020');
    }

    private function account(string $code): Account
    {
        return Account::withoutGlobalScopes()
            ->where('organization_id', $this->org->id)
            ->where('code', $code)
            ->firstOrFail();
    }

    /** Post a simple balanced journal entry and return the posted entry. */
    private function postEntry(
        string $date,
        string $description,
        array $lines // [['account_id'=>..., 'debit'=>..., 'credit'=>...], ...]
    ) {
        $draft = $this->postingEngine->createDraft($this->org, [
            'entry_date'  => $date,
            'description' => $description,
            'currency'    => 'PKR',
            'lines'       => $lines,
        ], $this->owner);

        return $this->postingEngine->postEntry($draft, $this->owner);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TRIAL BALANCE
    // ─────────────────────────────────────────────────────────────────────────

    public function test_trial_balance_debits_equal_credits(): void
    {
        // Post: Owner capital injection PKR 500,000
        // Dr. Cash 500,000 / Cr. Equity 500,000
        $this->postEntry('2025-08-15', 'Capital injection', [
            ['account_id' => $this->cashAccount->id,   'debit' => 500000, 'credit' => 0],
            ['account_id' => $this->equityAccount->id, 'debit' => 0,      'credit' => 500000],
        ]);

        // Post: Revenue recognition PKR 200,000
        // Dr. AR 200,000 / Cr. Revenue 200,000
        $this->postEntry('2025-09-10', 'Service invoice to client', [
            ['account_id' => $this->arAccount->id,      'debit' => 200000, 'credit' => 0],
            ['account_id' => $this->revenueAccount->id, 'debit' => 0,      'credit' => 200000],
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson("/api/v1/organizations/{$this->org->id}/reports/trial-balance?as_of=2025-09-30");

        $response->assertStatus(200)
            ->assertJsonPath('data.is_balanced', true);

        $totalDebit  = $response->json('data.totals.total_debit');
        $totalCredit = $response->json('data.totals.total_credit');

        $this->assertEquals($totalDebit, $totalCredit, 'Trial balance must balance: ∑Debit == ∑Credit');
        $this->assertEquals(700000.0, $totalDebit);
    }

    public function test_trial_balance_returns_correct_net_balance_per_account(): void
    {
        // Dr. Cash 300,000 / Cr. Equity 300,000
        $this->postEntry('2025-08-15', 'Capital', [
            ['account_id' => $this->cashAccount->id,   'debit' => 300000, 'credit' => 0],
            ['account_id' => $this->equityAccount->id, 'debit' => 0,      'credit' => 300000],
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson("/api/v1/organizations/{$this->org->id}/reports/trial-balance?as_of=2025-09-30");

        $response->assertStatus(200);
        $accounts = collect($response->json('data.accounts'));

        $cash   = $accounts->firstWhere('code', '1010');
        $equity = $accounts->firstWhere('code', '3010');

        // Cash is debit-normal → net = debit − credit = 300,000
        $this->assertEquals(300000.0, $cash['net_balance']);
        // Equity is credit-normal → net = credit − debit = 300,000
        $this->assertEquals(300000.0, $equity['net_balance']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PROFIT & LOSS
    // ─────────────────────────────────────────────────────────────────────────

    public function test_profit_and_loss_calculates_gross_and_net_profit(): void
    {
        // Revenue: 500,000
        $this->postEntry('2025-08-10', 'Software project revenue', [
            ['account_id' => $this->arAccount->id,      'debit' => 500000, 'credit' => 0],
            ['account_id' => $this->revenueAccount->id, 'debit' => 0,      'credit' => 500000],
        ]);

        // COGS: 150,000
        $this->postEntry('2025-08-15', 'Direct cost of goods sold', [
            ['account_id' => $this->cogsAccount->id, 'debit' => 150000, 'credit' => 0],
            ['account_id' => $this->apAccount->id,   'debit' => 0,      'credit' => 150000],
        ]);

        // Operating expense (Rent): 50,000
        $this->postEntry('2025-09-10', 'Office rent', [
            ['account_id' => $this->expenseAccount->id, 'debit' => 50000, 'credit' => 0],
            ['account_id' => $this->cashAccount->id,    'debit' => 0,     'credit' => 50000],
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson("/api/v1/organizations/{$this->org->id}/reports/profit-and-loss?from=2025-08-01&to=2025-09-30");

        $response->assertStatus(200);

        $data = $response->json('data');

        $this->assertEquals(500000.0, $data['revenue']['total'],              'Total revenue');
        $this->assertEquals(150000.0, $data['cost_of_goods_sold']['total'],   'COGS');
        $this->assertEquals(350000.0, $data['gross_profit'],                  'Gross Profit = 500k − 150k');
        $this->assertEquals(50000.0,  $data['operating_expenses']['total'],   'Operating expenses');
        $this->assertEquals(300000.0, $data['net_profit'],                    'Net Profit = 350k − 50k');
        $this->assertTrue($data['is_profitable']);
    }

    public function test_profit_and_loss_excludes_balance_sheet_accounts(): void
    {
        // Post capital injection — should NOT appear in P&L
        $this->postEntry('2025-08-15', 'Capital injection', [
            ['account_id' => $this->cashAccount->id,   'debit' => 1000000, 'credit' => 0],
            ['account_id' => $this->equityAccount->id, 'debit' => 0,       'credit' => 1000000],
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson("/api/v1/organizations/{$this->org->id}/reports/profit-and-loss?from=2025-08-01&to=2025-09-30");

        $response->assertStatus(200);

        // Revenue and expenses should both be zero — only BS accounts posted
        $this->assertEquals(0.0, $response->json('data.revenue.total'));
        $this->assertEquals(0.0, $response->json('data.net_profit'));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // BALANCE SHEET
    // ─────────────────────────────────────────────────────────────────────────

    public function test_balance_sheet_equation_holds(): void
    {
        // Capital injection: Dr. Cash 800k / Cr. Equity 800k
        $this->postEntry('2025-08-15', 'Capital injection', [
            ['account_id' => $this->cashAccount->id,   'debit' => 800000, 'credit' => 0],
            ['account_id' => $this->equityAccount->id, 'debit' => 0,      'credit' => 800000],
        ]);

        // Revenue: Dr. AR 300k / Cr. Revenue 300k
        $this->postEntry('2025-08-20', 'Revenue', [
            ['account_id' => $this->arAccount->id,      'debit' => 300000, 'credit' => 0],
            ['account_id' => $this->revenueAccount->id, 'debit' => 0,      'credit' => 300000],
        ]);

        // Expense: Dr. Rent 100k / Cr. Cash 100k
        $this->postEntry('2025-09-10', 'Rent', [
            ['account_id' => $this->expenseAccount->id, 'debit' => 100000, 'credit' => 0],
            ['account_id' => $this->cashAccount->id,    'debit' => 0,      'credit' => 100000],
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson("/api/v1/organizations/{$this->org->id}/reports/balance-sheet?as_of=2025-09-30");

        $response->assertStatus(200)
            ->assertJsonPath('data.is_balanced', true);

        $data = $response->json('data');

        // Assets: Cash = 800k − 100k = 700k; AR = 300k → Total Assets = 1,000,000
        $this->assertEquals(1000000.0, $data['assets']['total'], 'Total Assets');

        // Equity: Paid-in 800k + Retained Earnings (Revenue 300k − Expense 100k = 200k) = 1,000,000
        $this->assertEquals(200000.0,  $data['equity']['retained_earnings'], 'Retained Earnings = P&L');
        $this->assertEquals(1000000.0, $data['equity']['total'],             'Total Equity');

        // Assets == Liabilities + Equity
        $this->assertEquals(
            $data['assets']['total'],
            $data['total_liabilities_and_equity'],
            'Balance Sheet equation: Assets == Liabilities + Equity'
        );
    }

    public function test_balance_sheet_retained_earnings_matches_pl_net_profit(): void
    {
        // Revenue 400k / Expense 120k → Net Profit = 280k
        $this->postEntry('2025-08-15', 'Capital', [
            ['account_id' => $this->cashAccount->id,   'debit' => 400000, 'credit' => 0],
            ['account_id' => $this->equityAccount->id, 'debit' => 0,      'credit' => 400000],
        ]);
        $this->postEntry('2025-08-20', 'Revenue', [
            ['account_id' => $this->arAccount->id,      'debit' => 400000, 'credit' => 0],
            ['account_id' => $this->revenueAccount->id, 'debit' => 0,      'credit' => 400000],
        ]);
        $this->postEntry('2025-09-10', 'Expense', [
            ['account_id' => $this->expenseAccount->id, 'debit' => 120000, 'credit' => 0],
            ['account_id' => $this->cashAccount->id,    'debit' => 0,      'credit' => 120000],
        ]);

        $plResponse = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson("/api/v1/organizations/{$this->org->id}/reports/profit-and-loss?from=2025-08-01&to=2025-09-30");

        $bsResponse = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson("/api/v1/organizations/{$this->org->id}/reports/balance-sheet?as_of=2025-09-30");

        $netProfit        = $plResponse->json('data.net_profit');
        $retainedEarnings = $bsResponse->json('data.equity.retained_earnings');

        $this->assertEquals(280000.0, $netProfit,         'P&L Net Profit = 400k − 120k');
        $this->assertEquals($netProfit, $retainedEarnings, 'BS Retained Earnings == P&L Net Profit');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GENERAL LEDGER
    // ─────────────────────────────────────────────────────────────────────────

    public function test_general_ledger_shows_running_balance_per_line(): void
    {
        // Entry 1: Cash Dr 500k / Equity Cr 500k
        $this->postEntry('2025-08-15', 'Capital injection', [
            ['account_id' => $this->cashAccount->id,   'debit' => 500000, 'credit' => 0],
            ['account_id' => $this->equityAccount->id, 'debit' => 0,      'credit' => 500000],
        ]);
        // Entry 2: Cash Cr 80k / Expense Dr 80k
        $this->postEntry('2025-09-10', 'Rent payment', [
            ['account_id' => $this->expenseAccount->id, 'debit' => 80000, 'credit' => 0],
            ['account_id' => $this->cashAccount->id,    'debit' => 0,     'credit' => 80000],
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson("/api/v1/organizations/{$this->org->id}/reports/general-ledger"
                . "?from=2025-08-01&to=2025-09-30"
                . "&account_id={$this->cashAccount->id}");

        $response->assertStatus(200);

        $accounts = $response->json('data.accounts');
        $this->assertNotEmpty($accounts);

        $cashLedger = $accounts[0];
        $this->assertEquals('1010', $cashLedger['code']);
        $this->assertEquals(0.0, $cashLedger['opening_balance']); // nothing before 2025-07-01
        $this->assertNotEmpty($cashLedger['lines']);

        // Last running balance should equal closing balance
        $lastLine = end($cashLedger['lines']);
        $this->assertEquals($cashLedger['closing_balance'], $lastLine['running_balance']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // AR AGING
    // ─────────────────────────────────────────────────────────────────────────

    public function test_ar_aging_buckets_invoices_correctly(): void
    {
        $customer = Customer::create([
            'organization_id' => $this->org->id,
            'name'            => 'Karachi Tech Solutions',
            'email'           => 'billing@karachitechsolutions.pk',
            'currency'        => 'PKR',
            'created_by'      => $this->owner->id,
        ]);

        $asOf = '2025-09-30';

        // Invoice 1: due 2025-09-30 → 0 days overdue → Current bucket
        SalesInvoice::create([
            'organization_id' => $this->org->id,
            'customer_id'     => $customer->id,
            'invoice_number'  => 'INV-2025-00001',
            'issue_date'      => '2025-09-01',
            'due_date'        => '2025-09-30',
            'status'          => 'sent',
            'currency'        => 'PKR',
            'exchange_rate'   => 1,
            'subtotal'        => 100000,
            'tax_rate'        => 0,
            'tax_amount'      => 0,
            'total_amount'    => 100000,
            'amount_paid'     => 0,
            'created_by'      => $this->owner->id,
        ]);

        // Invoice 2: due 2025-09-10 → 20 days overdue → 1–30 bucket
        SalesInvoice::create([
            'organization_id' => $this->org->id,
            'customer_id'     => $customer->id,
            'invoice_number'  => 'INV-2025-00002',
            'issue_date'      => '2025-08-01',
            'due_date'        => '2025-09-10',
            'status'          => 'sent',
            'currency'        => 'PKR',
            'exchange_rate'   => 1,
            'subtotal'        => 50000,
            'tax_rate'        => 0,
            'tax_amount'      => 0,
            'total_amount'    => 50000,
            'amount_paid'     => 0,
            'created_by'      => $this->owner->id,
        ]);

        // Invoice 3: due 2025-06-01 → 121 days overdue → Over 90 bucket
        SalesInvoice::create([
            'organization_id' => $this->org->id,
            'customer_id'     => $customer->id,
            'invoice_number'  => 'INV-2025-00003',
            'issue_date'      => '2025-05-01',
            'due_date'        => '2025-06-01',
            'status'          => 'partial',
            'currency'        => 'PKR',
            'exchange_rate'   => 1,
            'subtotal'        => 200000,
            'tax_rate'        => 0,
            'tax_amount'      => 0,
            'total_amount'    => 200000,
            'amount_paid'     => 50000, // 150k still outstanding
            'created_by'      => $this->owner->id,
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson("/api/v1/organizations/{$this->org->id}/reports/ar-aging?as_of={$asOf}");

        $response->assertStatus(200);
        $buckets = $response->json('data.buckets');

        $this->assertCount(1, $buckets['current']['items'],  'Invoice 1 in Current bucket');
        $this->assertCount(1, $buckets['1_30']['items'],     'Invoice 2 in 1–30 days bucket');
        $this->assertCount(1, $buckets['over_90']['items'],  'Invoice 3 in Over 90 days bucket');

        $this->assertEquals(150000.0, $buckets['over_90']['total'], 'Only balance_due (200k − 50k paid)');

        $grandTotal = $response->json('data.grand_total');
        $this->assertEquals(300000.0, $grandTotal, 'Grand total: 100k + 50k + 150k');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // AP AGING
    // ─────────────────────────────────────────────────────────────────────────

    public function test_ap_aging_buckets_bills_correctly(): void
    {
        $vendor = Vendor::create([
            'organization_id' => $this->org->id,
            'name'            => 'Pak Chemicals Supplies',
            'email'           => 'accounts@pakchemicals.pk',
            'currency'        => 'PKR',
            'created_by'      => $this->owner->id,
        ]);

        // Bill: 45 days overdue → 31–60 bucket
        PurchaseBill::create([
            'organization_id'    => $this->org->id,
            'vendor_id'          => $vendor->id,
            'bill_number'        => 'BILL-2025-00001',
            'vendor_invoice_ref' => 'CHEM-9876',
            'bill_date'          => '2025-08-01',
            'due_date'           => '2025-08-15', // 46 days before Sep 30
            'status'             => 'received',
            'currency'           => 'PKR',
            'exchange_rate'      => 1,
            'subtotal'           => 75000,
            'wht_rate'           => 0,
            'wht_amount'         => 0,
            'tax_amount'         => 0,
            'total_amount'       => 75000,
            'net_payable'        => 75000,
            'amount_paid'        => 0,
            'created_by'         => $this->owner->id,
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson("/api/v1/organizations/{$this->org->id}/reports/ap-aging?as_of=2025-09-30");

        $response->assertStatus(200);
        $buckets = $response->json('data.buckets');

        $this->assertCount(1, $buckets['31_60']['items'], 'Bill is 46 days overdue → 31–60 bucket');
        $this->assertEquals(75000.0, $response->json('data.grand_total'));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TENANT ISOLATION
    // ─────────────────────────────────────────────────────────────────────────

    public function test_reports_enforce_tenant_isolation(): void
    {
        $intruder      = User::factory()->create(['email' => 'intruder@evil.pk']);
        $intruderToken = $intruder->createToken('token')->plainTextToken;

        $endpoints = [
            "/api/v1/organizations/{$this->org->id}/reports/trial-balance?as_of=2025-09-30",
            "/api/v1/organizations/{$this->org->id}/reports/profit-and-loss?from=2025-07-01&to=2025-09-30",
            "/api/v1/organizations/{$this->org->id}/reports/balance-sheet?as_of=2025-09-30",
            "/api/v1/organizations/{$this->org->id}/reports/ar-aging?as_of=2025-09-30",
            "/api/v1/organizations/{$this->org->id}/reports/ap-aging?as_of=2025-09-30",
        ];

        foreach ($endpoints as $endpoint) {
            $this->withHeader('Authorization', "Bearer {$intruderToken}")
                ->getJson($endpoint)
                ->assertStatus(404);
        }
    }
}
