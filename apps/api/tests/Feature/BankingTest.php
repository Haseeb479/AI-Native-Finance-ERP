<?php

namespace Tests\Feature;

use App\Domain\Accounting\ChartOfAccounts\Models\Account;
use App\Domain\Accounting\ChartOfAccounts\Templates\PakistanSmeChartTemplate;
use App\Domain\Accounting\Journal\Models\JournalEntry;
use App\Domain\Accounting\Posting\Services\PostingEngine;
use App\Domain\Accounting\Period\Services\PeriodManager;
use App\Domain\Banking\Models\BankAccount;
use App\Domain\Banking\Models\BankTransaction;
use App\Domain\Banking\Services\ReconciliationService;
use App\Domain\Organization\Models\Organization;
use App\Domain\Sales\Models\Customer;
use App\Domain\Sales\Services\InvoiceService;
use App\Models\User;
use Database\Seeders\AccountTypeSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BankingTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Organization $org;
    private string $token;
    private Account $bankGlAccount;
    private Account $revenueAccount;
    private Account $arAccount;
    private BankAccount $bankAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);
        $this->seed(AccountTypeSeeder::class);

        $this->owner = User::factory()->create([
            'name' => 'Khurram Shah',
            'email' => 'khurram@distributors.pk',
        ]);

        $this->token = $this->owner->createToken('test-token')->plainTextToken;

        $this->org = Organization::create([
            'name' => 'Shah Wholesale Distribution',
            'legal_name' => 'Shah Wholesale Distribution (Pvt) Ltd',
            'base_currency' => 'PKR',
            'fiscal_year_start_month' => 7,
        ]);
        $this->org->users()->attach($this->owner->id, ['role' => 'owner', 'is_default' => true]);

        // Seed COA & Fiscal Year 2025
        PakistanSmeChartTemplate::seedForOrganization($this->org);
        app(PeriodManager::class)->generateFiscalYear($this->org, 2025);

        // Required Accounts
        $this->bankGlAccount = Account::where('organization_id', $this->org->id)->where('code', '1020')->firstOrFail();
        $this->arAccount = Account::where('organization_id', $this->org->id)->where('code', '1030')->firstOrFail();
        $this->revenueAccount = Account::where('organization_id', $this->org->id)->where('code', '4010')->firstOrFail();

        $this->bankAccount = BankAccount::create([
            'organization_id' => $this->org->id,
            'account_id' => $this->bankGlAccount->id,
            'bank_name' => 'Meezan Bank Limited',
            'account_title' => 'Shah Wholesale Operating A/C',
            'account_number' => '01020304050607',
            'iban' => 'PK36MEZN0001020304050607',
            'branch_name' => 'Gulberg Branch Lahore',
            'currency' => 'PKR',
            'opening_balance' => 500000.00,
            'current_balance' => 500000.00,
            'is_active' => true,
        ]);
    }

    public function test_can_create_bank_account_linked_to_gl(): void
    {
        $hblGl = Account::where('organization_id', $this->org->id)->where('code', '1021')->firstOrFail();

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson("/api/v1/organizations/{$this->org->id}/bank-accounts", [
                'account_id' => $hblGl->id,
                'bank_name' => 'Habib Bank Limited (HBL)',
                'account_title' => 'Shah Wholesale Secondary',
                'account_number' => '99887766554433',
                'iban' => 'PK12HABB0099887766554433',
                'branch_name' => 'Mall Road Branch Lahore',
                'opening_balance' => 100000.00,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.bank_name', 'Habib Bank Limited (HBL)')
            ->assertJsonPath('data.account_number', '99887766554433')
            ->assertJsonPath('data.current_balance', '100000.0000');

        $this->assertDatabaseHas('bank_accounts', [
            'organization_id' => $this->org->id,
            'account_number' => '99887766554433',
        ]);
    }

    public function test_can_import_and_parse_csv_bank_statement(): void
    {
        $csvData = implode("\n", [
            'Date,Description,Reference,Withdrawal,Deposit,Balance',
            '2025-08-10,"IBFT From Customer Al-Madina",IBFT-90812,,150000.00,650000.00',
            '2025-08-12,"Vendor Packaging Supplies Chq #4091",CHQ-4091,50000.00,,600000.00',
            '2025-08-14,"Bank Service Charges & FED",TXN-8821,1160.00,,598840.00',
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson("/api/v1/organizations/{$this->org->id}/bank-accounts/{$this->bankAccount->id}/import-statement", [
                'csv_content' => $csvData,
                'filename' => 'meezan_statement_aug2025.csv',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.statement_number', 'STMT-202508-0001')
            ->assertJsonPath('data.closing_balance', '598840.0000')
            ->assertJsonPath('meta.transactions_imported', 3);

        $this->assertDatabaseHas('bank_statements', [
            'bank_account_id' => $this->bankAccount->id,
            'statement_number' => 'STMT-202508-0001',
        ]);

        $this->assertDatabaseHas('bank_transactions', [
            'bank_account_id' => $this->bankAccount->id,
            'reference' => 'IBFT-90812',
            'type' => 'credit',
            'amount' => '150000.0000',
            'reconciliation_status' => 'unreconciled',
        ]);

        $this->assertDatabaseHas('bank_transactions', [
            'bank_account_id' => $this->bankAccount->id,
            'reference' => 'CHQ-4091',
            'type' => 'debit',
            'amount' => '50000.0000',
            'reconciliation_status' => 'unreconciled',
        ]);
    }

    public function test_duplicate_statement_rows_are_prevented_by_fingerprint(): void
    {
        $csvData = implode("\n", [
            'Date,Description,Reference,Withdrawal,Deposit,Balance',
            '2025-08-10,"IBFT From Customer Al-Madina",IBFT-90812,,150000.00,650000.00',
        ]);

        $reconciliationService = app(ReconciliationService::class);

        // First import
        $stmt1 = $reconciliationService->importStatement($this->bankAccount, $csvData, 'file1.csv', $this->owner);
        $this->assertCount(1, $stmt1->transactions);

        // Second import of same transaction row
        $stmt2 = $reconciliationService->importStatement($this->bankAccount, $csvData, 'file2.csv', $this->owner);
        // Duplicate is skipped, so second statement has 0 new transactions
        $this->assertCount(0, $stmt2->transactions);

        // Overall count in database remains exactly 1
        $count = BankTransaction::where('bank_account_id', $this->bankAccount->id)->count();
        $this->assertEquals(1, $count);
    }

    public function test_reconciliation_suggests_exact_matches_against_posted_gl(): void
    {
        // 1. Post a Customer Invoice and record a payment of 150,000 into Meezan Bank (GL 1020)
        $customer = Customer::create([
            'organization_id' => $this->org->id,
            'name' => 'Metro Retail',
            'payment_terms_days' => 30,
        ]);

        $invoice = app(InvoiceService::class)->createInvoice($this->org, [
            'customer_id' => $customer->id,
            'issue_date' => '2025-08-10',
            'lines' => [
                [
                    'revenue_account_id' => $this->revenueAccount->id,
                    'description' => 'Bulk Goods',
                    'quantity' => 1,
                    'unit_price' => 150000.00,
                    'tax_rate' => 0.00,
                ],
            ],
        ], $this->owner);

        app(InvoiceService::class)->postInvoice($invoice, $this->owner);
        $paidInvoice = app(InvoiceService::class)->recordPayment($invoice, [
            'amount' => 150000.00,
            'payment_date' => '2025-08-10',
            'reference' => 'IBFT-90812',
            'bank_account_id' => $this->bankGlAccount->id,
        ], $this->owner);

        // 2. Import Bank Statement containing the matching deposit of 150,000 with reference IBFT-90812
        $csvData = implode("\n", [
            'Date,Description,Reference,Withdrawal,Deposit,Balance',
            '2025-08-10,"IBFT From Metro Retail",IBFT-90812,,150000.00,650000.00',
        ]);

        app(ReconciliationService::class)->importStatement($this->bankAccount, $csvData, 'stmt.csv', $this->owner);

        // 3. Request suggestions via API
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson("/api/v1/organizations/{$this->org->id}/bank-accounts/{$this->bankAccount->id}/suggestions");

        $response->assertStatus(200);
        $suggestions = $response->json('data');

        $this->assertCount(1, $suggestions);
        $firstMatch = $suggestions[0]['matches'][0];

        $this->assertGreaterThanOrEqual(0.90, $firstMatch['confidence']); // High confidence match
        $this->assertStringContainsString('Exact amount match', $firstMatch['reason']);
        $this->assertStringContainsString('Reference match', $firstMatch['reason']);
    }

    public function test_can_manually_reconcile_and_unreconcile_bank_transaction(): void
    {
        // 1. Create a posted GL Journal entry directly affecting Bank
        $postingEngine = app(PostingEngine::class);
        $draft = $postingEngine->createDraft($this->org, [
            'entry_date' => '2025-08-15',
            'description' => 'Direct Capital Injection',
            'reference' => 'CAP-INJECT-01',
            'lines' => [
                ['account_id' => $this->bankGlAccount->id, 'debit' => 250000.00, 'credit' => 0.00],
                ['account_id' => Account::where('organization_id', $this->org->id)->where('code', '3010')->first()->id, 'debit' => 0.00, 'credit' => 250000.00],
            ],
        ], $this->owner);

        $postedJournal = $postingEngine->postEntry($draft, $this->owner);

        // 2. Bank Transaction
        $bankTx = BankTransaction::create([
            'organization_id' => $this->org->id,
            'bank_account_id' => $this->bankAccount->id,
            'transaction_date' => '2025-08-15',
            'description' => 'Owner Capital Online Transfer',
            'reference' => 'CAP-INJECT-01',
            'type' => 'credit',
            'amount' => 250000.00,
            'reconciliation_status' => 'unreconciled',
            'fingerprint' => BankTransaction::generateFingerprint('2025-08-15', '250000.00', 'CAP-INJECT-01', 'credit'),
        ]);

        // 3. Reconcile via API
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson("/api/v1/organizations/{$this->org->id}/bank-transactions/{$bankTx->id}/reconcile", [
                'journal_entry_id' => $postedJournal->id,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.reconciliation_status', 'reconciled')
            ->assertJsonPath('data.matched_journal_entry_id', $postedJournal->id);

        $bankTx->refresh();
        $this->assertTrue($bankTx->isReconciled());
        $this->assertEquals($postedJournal->id, $bankTx->matched_journal_entry_id);

        // 4. Unreconcile via API
        $unreconcileResponse = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson("/api/v1/organizations/{$this->org->id}/bank-transactions/{$bankTx->id}/unreconcile");

        $unreconcileResponse->assertStatus(200)
            ->assertJsonPath('data.reconciliation_status', 'unreconciled')
            ->assertJsonPath('data.matched_journal_entry_id', null);

        $bankTx->refresh();
        $this->assertFalse($bankTx->isReconciled());
        $this->assertNull($bankTx->matched_journal_entry_id);
    }

    public function test_tenant_isolation_prevents_unauthorized_bank_access(): void
    {
        $intruder = User::factory()->create(['email' => 'intruder@other.pk']);
        $intruderToken = $intruder->createToken('intruder-token')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$intruderToken}")
            ->getJson("/api/v1/organizations/{$this->org->id}/bank-accounts");

        $response->assertStatus(404);
    }
}
