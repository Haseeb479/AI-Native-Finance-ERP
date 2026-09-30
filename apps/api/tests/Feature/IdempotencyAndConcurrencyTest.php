<?php

namespace Tests\Feature;

use App\Domain\Accounting\ChartOfAccounts\Models\Account;
use App\Domain\Accounting\ChartOfAccounts\Templates\PakistanSmeChartTemplate;
use App\Domain\Accounting\Journal\Models\JournalEntry;
use App\Domain\Accounting\Period\Models\AccountingPeriod;
use App\Domain\Accounting\Period\Services\PeriodManager;
use App\Domain\Accounting\Posting\Exceptions\ClosedPeriodException;
use App\Domain\Accounting\Posting\Services\PostingEngine;
use App\Domain\Organization\Models\Organization;
use App\Domain\Purchasing\Models\PurchaseBill;
use App\Domain\Purchasing\Models\Vendor;
use App\Domain\Purchasing\Services\BillService;
use App\Domain\Revenue\Models\RevenueContract;
use App\Domain\Revenue\Models\RevenueSchedule;
use App\Domain\Revenue\Services\RevenueRecognitionService;
use App\Domain\Sales\Models\Customer;
use App\Domain\Sales\Models\SalesInvoice;
use App\Domain\Sales\Services\InvoiceService;
use App\Models\User;
use Database\Seeders\AccountTypeSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class IdempotencyAndConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private User $user;
    private string $token;
    private PostingEngine $postingEngine;
    private PeriodManager $periodManager;
    private InvoiceService $invoiceService;
    private BillService $billService;
    private RevenueRecognitionService $revRecService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);
        $this->seed(AccountTypeSeeder::class);

        $this->user = User::factory()->create([
            'name' => 'Finance Controller',
            'email' => 'controller@enterprise.pk',
        ]);
        $this->token = $this->user->createToken('test-token')->plainTextToken;

        $this->org = Organization::create([
            'name' => 'Idempotency Test Org',
            'legal_name' => 'Idempotency Test Org (Pvt) Ltd',
            'base_currency' => 'PKR',
            'fiscal_year_start_month' => 7,
        ]);
        $this->org->users()->attach($this->user->id, ['role' => 'owner', 'is_default' => true]);

        PakistanSmeChartTemplate::seedForOrganization($this->org);
        $this->periodManager = app(PeriodManager::class);
        $this->periodManager->generateFiscalYear($this->org, 2025);

        $this->postingEngine = app(PostingEngine::class);
        $this->invoiceService = app(InvoiceService::class);
        $this->billService = app(BillService::class);
        $this->revRecService = app(RevenueRecognitionService::class);
    }

    /**
     * P1-22: Test that Idempotency-Key header replays exact cached response and prevents duplicate mutations.
     */
    public function test_idempotency_header_replays_cached_response(): void
    {
        $cash = Account::where('organization_id', $this->org->id)->where('code', '1010')->firstOrFail();
        $revenue = Account::where('organization_id', $this->org->id)->where('code', '4010')->firstOrFail();

        $idempotencyKey = 'idemp-' . Str::uuid();
        $payload = [
            'entry_date' => '2025-08-15',
            'description' => 'Idempotent cash sale',
            'lines' => [
                ['account_id' => $cash->id, 'debit' => 12500.0, 'credit' => 0.0],
                ['account_id' => $revenue->id, 'debit' => 0.0, 'credit' => 12500.0],
            ],
        ];

        // First execution -> 201 Created
        $response1 = $this->withHeaders([
            'Authorization' => "Bearer {$this->token}",
            'Idempotency-Key' => $idempotencyKey,
        ])->postJson("/api/v1/organizations/{$this->org->id}/journals", $payload);

        $response1->assertStatus(201);
        $createdId = $response1->json('data.id');
        $this->assertNotNull($createdId);

        // Second execution with identical Idempotency-Key -> 201 Replay with X-Idempotent-Replay header
        $response2 = $this->withHeaders([
            'Authorization' => "Bearer {$this->token}",
            'Idempotency-Key' => $idempotencyKey,
        ])->postJson("/api/v1/organizations/{$this->org->id}/journals", $payload);

        $response2->assertStatus(201);
        $response2->assertHeader('X-Idempotent-Replay', 'true');
        $this->assertEquals($createdId, $response2->json('data.id'));

        // Verify only 1 journal entry exists in database
        $this->assertEquals(1, JournalEntry::withoutGlobalScopes()->where('organization_id', $this->org->id)->count());
    }

    /**
     * P1-22: Test that reusing an Idempotency-Key with different payload is rejected with 422.
     */
    public function test_idempotency_header_rejects_payload_tampering(): void
    {
        $cash = Account::where('organization_id', $this->org->id)->where('code', '1010')->firstOrFail();
        $revenue = Account::where('organization_id', $this->org->id)->where('code', '4010')->firstOrFail();

        $idempotencyKey = 'idemp-tamper-' . Str::uuid();
        $payloadA = [
            'entry_date' => '2025-08-15',
            'description' => 'Original transaction',
            'lines' => [
                ['account_id' => $cash->id, 'debit' => 5000.0, 'credit' => 0.0],
                ['account_id' => $revenue->id, 'debit' => 0.0, 'credit' => 5000.0],
            ],
        ];

        $responseA = $this->withHeaders([
            'Authorization' => "Bearer {$this->token}",
            'Idempotency-Key' => $idempotencyKey,
        ])->postJson("/api/v1/organizations/{$this->org->id}/journals", $payloadA);
        $responseA->assertStatus(201);

        // Attempt reuse with altered debit amount (10000 instead of 5000)
        $payloadB = [
            'entry_date' => '2025-08-15',
            'description' => 'Tampered transaction',
            'lines' => [
                ['account_id' => $cash->id, 'debit' => 10000.0, 'credit' => 0.0],
                ['account_id' => $revenue->id, 'debit' => 0.0, 'credit' => 10000.0],
            ],
        ];

        $responseB = $this->withHeaders([
            'Authorization' => "Bearer {$this->token}",
            'Idempotency-Key' => $idempotencyKey,
        ])->postJson("/api/v1/organizations/{$this->org->id}/journals", $payloadB);

        $responseB->assertStatus(422);
        $responseB->assertJsonPath('errors.0.code', 'IDEMPOTENCY_KEY_MISMATCH');
    }

    /**
     * P1-22: Test that sales invoice posting is idempotent and safe against duplicate GL journals.
     */
    public function test_sales_invoice_post_is_idempotent(): void
    {
        $customer = Customer::create([
            'organization_id' => $this->org->id,
            'name' => 'Acme Corporation',
            'currency' => 'PKR',
        ]);
        $revenueAccount = Account::where('organization_id', $this->org->id)->where('code', '4010')->firstOrFail();

        $invoice = $this->invoiceService->createInvoice($this->org, [
            'customer_id' => $customer->id,
            'issue_date' => '2025-08-10',
            'due_date' => '2025-09-10',
            'lines' => [
                [
                    'revenue_account_id' => $revenueAccount->id,
                    'description' => 'Consulting Services',
                    'quantity' => 1,
                    'unit_price' => 50000.0,
                ],
            ],
        ], $this->user);

        // First post
        $posted1 = $this->invoiceService->postInvoice($invoice, $this->user);
        $this->assertEquals('sent', $posted1->status);
        $firstJournalId = $posted1->journal_entry_id;
        $this->assertNotNull($firstJournalId);

        // Second post call (idempotent replay)
        $posted2 = $this->invoiceService->postInvoice($invoice, $this->user);
        $this->assertEquals('sent', $posted2->status);
        $this->assertEquals($firstJournalId, $posted2->journal_entry_id);

        // Verify only 1 GL journal entry was created for this invoice
        $journalsCount = JournalEntry::withoutGlobalScopes()
            ->where('organization_id', $this->org->id)
            ->where('source_type', 'invoice')
            ->where('source_id', $invoice->id)
            ->count();
        $this->assertEquals(1, $journalsCount);
    }

    /**
     * P1-22: Test purchase bill posting is idempotent.
     */
    public function test_purchase_bill_post_is_idempotent(): void
    {
        $vendor = Vendor::create([
            'organization_id' => $this->org->id,
            'name' => 'Office Suppliers Ltd',
            'currency' => 'PKR',
        ]);
        $expenseAccount = Account::where('organization_id', $this->org->id)->where('code', '6020')->firstOrFail();

        $bill = $this->billService->createBill($this->org, [
            'vendor_id' => $vendor->id,
            'bill_date' => '2025-08-10',
            'due_date' => '2025-09-10',
            'lines' => [
                [
                    'expense_account_id' => $expenseAccount->id,
                    'description' => 'Office Rent',
                    'quantity' => 1,
                    'unit_price' => 30000.0,
                ],
            ],
        ], $this->user);

        $posted1 = $this->billService->postBill($bill, $this->user);
        $this->assertEquals('received', $posted1->status);
        $journalId1 = $posted1->journal_entry_id;

        $posted2 = $this->billService->postBill($bill, $this->user);
        $this->assertEquals('received', $posted2->status);
        $this->assertEquals($journalId1, $posted2->journal_entry_id);

        $billJournals = JournalEntry::withoutGlobalScopes()
            ->where('organization_id', $this->org->id)
            ->where('source_type', 'bill')
            ->where('source_id', $bill->id)
            ->count();
        $this->assertEquals(1, $billJournals);
    }

    /**
     * P1-22: Test Revenue Recognition schedule recognition is idempotent.
     */
    public function test_revenue_recognition_schedule_is_idempotent(): void
    {
        $customer = Customer::create([
            'organization_id' => $this->org->id,
            'name' => 'SaaS Client',
            'currency' => 'PKR',
        ]);
        $defRev = Account::where('organization_id', $this->org->id)->where('code', '2010')->firstOrFail();
        $revAcc = Account::where('organization_id', $this->org->id)->where('code', '4010')->firstOrFail();

        $contract = RevenueContract::create([
            'organization_id' => $this->org->id,
            'customer_id' => $customer->id,
            'deferred_revenue_account_id' => $defRev->id,
            'revenue_account_id' => $revAcc->id,
            'contract_number' => 'REV-2025-001',
            'title' => 'Annual SaaS Subscription',
            'total_contract_value' => 120000.0,
            'start_date' => '2025-07-01',
            'end_date' => '2025-12-31',
            'status' => 'active',
        ]);

        $period = AccountingPeriod::where('organization_id', $this->org->id)->firstOrFail();
        $schedule = RevenueSchedule::create([
            'organization_id' => $this->org->id,
            'revenue_contract_id' => $contract->id,
            'accounting_period_id' => $period->id,
            'schedule_date' => '2025-07-31',
            'amount' => 20000.0,
            'cumulative_recognized' => 0.0,
            'status' => 'pending',
        ]);

        // First recognition
        $rec1 = $this->revRecService->recognizeSchedule($schedule, $this->user);
        $this->assertEquals('posted', $rec1->status);
        $firstJournal = $rec1->journal_entry_id;

        // Second recognition (idempotent)
        $rec2 = $this->revRecService->recognizeSchedule($schedule, $this->user);
        $this->assertEquals('posted', $rec2->status);
        $this->assertEquals($firstJournal, $rec2->journal_entry_id);

        $journals = JournalEntry::withoutGlobalScopes()
            ->where('organization_id', $this->org->id)
            ->where('source_type', 'revenue_recognition')
            ->where('source_id', $schedule->id)
            ->count();
        $this->assertEquals(1, $journals);
    }

    /**
     * P1-20: Test that closing an accounting period prevents any concurrent journal posting.
     */
    public function test_period_close_prevents_subsequent_post(): void
    {
        $period = AccountingPeriod::where('organization_id', $this->org->id)
            ->where('period_number', 1)
            ->firstOrFail();

        $cash = Account::where('organization_id', $this->org->id)->where('code', '1010')->firstOrFail();
        $revenue = Account::where('organization_id', $this->org->id)->where('code', '4010')->firstOrFail();

        // Create draft journal in period 1
        $draft = $this->postingEngine->createDraft($this->org, [
            'entry_date' => $period->start_date->toDateString(),
            'accounting_period_id' => $period->id,
            'description' => 'Draft before close',
            'lines' => [
                ['account_id' => $cash->id, 'debit' => 1000.0, 'credit' => 0.0],
                ['account_id' => $revenue->id, 'debit' => 0.0, 'credit' => 1000.0],
            ],
        ], $this->user);

        // Close the period
        $closed = $this->periodManager->closePeriod($period, $this->user);
        $this->assertEquals('closed', $closed->status);

        // Attempting to post draft into closed period must throw ClosedPeriodException
        $this->expectException(ClosedPeriodException::class);
        $this->postingEngine->postEntry($draft, $this->user);
    }
}
