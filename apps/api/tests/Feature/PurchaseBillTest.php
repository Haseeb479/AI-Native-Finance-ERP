<?php

namespace Tests\Feature;

use App\Domain\Accounting\ChartOfAccounts\Models\Account;
use App\Domain\Accounting\ChartOfAccounts\Templates\PakistanSmeChartTemplate;
use App\Domain\Accounting\Period\Services\PeriodManager;
use App\Domain\Organization\Models\Organization;
use App\Domain\Purchasing\Models\PurchaseBill;
use App\Domain\Purchasing\Models\Vendor;
use App\Domain\Purchasing\Services\BillService;
use App\Models\User;
use Database\Seeders\AccountTypeSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseBillTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Organization $org;
    private string $token;
    private Vendor $vendor;
    private Account $expenseAccount;
    private Account $apAccount;
    private Account $whtAccount;
    private Account $bankAccount;

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
        // 5010 = Cost of Goods Sold, 2010 = Trade Creditors (AP), 2030 = WHT Payable, 1020 = Bank
        $this->expenseAccount = Account::where('organization_id', $this->org->id)->where('code', '5010')->firstOrFail();
        $this->apAccount = Account::where('organization_id', $this->org->id)->where('code', '2010')->firstOrFail();
        $this->whtAccount = Account::where('organization_id', $this->org->id)->where('code', '2030')->firstOrFail();
        $this->bankAccount = Account::where('organization_id', $this->org->id)->where('code', '1020')->firstOrFail();

        $this->vendor = Vendor::create([
            'organization_id' => $this->org->id,
            'name' => 'Pak Chemicals & Packaging Co',
            'legal_name' => 'Pak Chemicals Ltd',
            'ntn' => '7654321-0',
            'strn' => '17-01-7654-321-00',
            'city' => 'Lahore',
            'payment_terms_days' => 30,
            'default_expense_account_id' => $this->expenseAccount->id,
        ]);
    }

    public function test_can_create_vendor_with_pakistan_tax_identifiers(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson("/api/v1/organizations/{$this->org->id}/vendors", [
                'name' => 'National Electric & Solar Supplies',
                'legal_name' => 'National Electric Co',
                'ntn' => '3344556-7',
                'strn' => '17-05-3344-556-77',
                'city' => 'Karachi',
                'province' => 'Sindh',
                'payment_terms_days' => 45,
                'default_expense_account_id' => $this->expenseAccount->id,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.name', 'National Electric & Solar Supplies')
            ->assertJsonPath('data.ntn', '3344556-7')
            ->assertJsonPath('data.payment_terms_days', 45);

        $this->assertDatabaseHas('vendors', [
            'organization_id' => $this->org->id,
            'name' => 'National Electric & Solar Supplies',
            'ntn' => '3344556-7',
        ]);
    }

    public function test_can_create_draft_purchase_bill_with_wht_deduction(): void
    {
        // 5% WHT on Services/Supplies under Section 153 of Pakistan Income Tax Ordinance
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson("/api/v1/organizations/{$this->org->id}/bills", [
                'vendor_id' => $this->vendor->id,
                'bill_date' => '2025-08-15',
                'due_date' => '2025-09-14',
                'vendor_invoice_ref' => 'SUP-99812',
                'wht_rate' => 5.00,
                'lines' => [
                    [
                        'expense_account_id' => $this->expenseAccount->id,
                        'description' => 'Industrial packaging materials batch #42',
                        'quantity' => 10,
                        'unit_price' => 10000.00,
                    ],
                ],
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.bill_number', 'BILL-2025-00001')
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.subtotal', '100000.0000')
            ->assertJsonPath('data.wht_rate', '5.00')
            ->assertJsonPath('data.wht_amount', '5000.0000')
            ->assertJsonPath('data.total_amount', '100000.0000')
            ->assertJsonPath('data.net_payable', '95000.0000');

        $this->assertDatabaseHas('purchase_bills', [
            'organization_id' => $this->org->id,
            'bill_number' => 'BILL-2025-00001',
            'vendor_invoice_ref' => 'SUP-99812',
            'status' => 'draft',
        ]);
    }

    public function test_posting_bill_creates_and_posts_balanced_gl_journal_entry(): void
    {
        $billService = app(BillService::class);

        $draft = $billService->createBill($this->org, [
            'vendor_id' => $this->vendor->id,
            'bill_date' => '2025-08-15',
            'due_date' => '2025-09-14',
            'vendor_invoice_ref' => 'SUP-77123',
            'wht_rate' => 5.00, // 5% WHT
            'lines' => [
                [
                    'expense_account_id' => $this->expenseAccount->id,
                    'description' => 'Raw Materials Batch',
                    'quantity' => 2,
                    'unit_price' => 50000.00, // 100,000 subtotal
                ],
            ],
        ], $this->owner);

        $this->assertEquals(100000.00, (float) $draft->subtotal);
        $this->assertEquals(5000.00, (float) $draft->wht_amount);
        $this->assertEquals(95000.00, (float) $draft->net_payable);

        // Post the bill via API
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson("/api/v1/organizations/{$this->org->id}/bills/{$draft->id}/post");

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'received');

        $draft->refresh();
        $this->assertNotNull($draft->journal_entry_id);
        $this->assertEquals('received', $draft->status);

        // Check linked GL Journal Entry
        $journal = $draft->journalEntry;
        $this->assertNotNull($journal);
        $this->assertTrue($journal->isPosted());
        $this->assertTrue($journal->isBalanced());

        // Invariant: Total Debit == Total Credit == 100,000
        $this->assertEquals(100000.00, $journal->totalDebit());
        $this->assertEquals(100000.00, $journal->totalCredit());

        // Debit: Expense Account (5010) = 100,000
        $expenseLine = $journal->lines->firstWhere('account_id', $this->expenseAccount->id);
        $this->assertNotNull($expenseLine);
        $this->assertEquals(100000.00, (float) $expenseLine->debit);

        // Credit: WHT Payable (2030) = 5,000
        $whtLine = $journal->lines->firstWhere('account_id', $this->whtAccount->id);
        $this->assertNotNull($whtLine);
        $this->assertEquals(5000.00, (float) $whtLine->credit);

        // Credit: Accounts Payable (2010) = 95,000 (Net Payable)
        $apLine = $journal->lines->firstWhere('account_id', $this->apAccount->id);
        $this->assertNotNull($apLine);
        $this->assertEquals(95000.00, (float) $apLine->credit);
    }

    public function test_recording_partial_and_full_vendor_bill_payments(): void
    {
        $billService = app(BillService::class);

        $bill = $billService->createBill($this->org, [
            'vendor_id' => $this->vendor->id,
            'bill_date' => '2025-08-15',
            'due_date' => '2025-09-14',
            'wht_rate' => 0.00,
            'lines' => [
                [
                    'expense_account_id' => $this->expenseAccount->id,
                    'description' => 'Packaging services',
                    'quantity' => 1,
                    'unit_price' => 50000.00,
                ],
            ],
        ], $this->owner);

        $bill = $billService->postBill($bill, $this->owner);
        $this->assertEquals(50000.00, $bill->balanceDue());

        // Partial payment: 20,000
        $response1 = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson("/api/v1/organizations/{$this->org->id}/bills/{$bill->id}/payments", [
                'amount' => 20000.00,
                'reference' => 'Cheque #102948',
            ]);

        $response1->assertStatus(200)
            ->assertJsonPath('data.status', 'partial')
            ->assertJsonPath('data.amount_paid', '20000.0000')
            ->assertJsonPath('data.balance_due', 30000);

        // Full remaining payment: 30,000
        $response2 = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson("/api/v1/organizations/{$this->org->id}/bills/{$bill->id}/payments", [
                'amount' => 30000.00,
                'reference' => 'IBFT Transfer',
            ]);

        $response2->assertStatus(200)
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.amount_paid', '50000.0000')
            ->assertJsonPath('data.balance_due', 0);
    }

    public function test_prevents_duplicate_vendor_invoice_reference(): void
    {
        $billService = app(BillService::class);

        $billService->createBill($this->org, [
            'vendor_id' => $this->vendor->id,
            'bill_date' => '2025-08-15',
            'due_date' => '2025-09-14',
            'vendor_invoice_ref' => 'INV-SAME-REF',
            'lines' => [
                [
                    'expense_account_id' => $this->expenseAccount->id,
                    'description' => 'Test',
                    'quantity' => 1,
                    'unit_price' => 1000.00,
                ],
            ],
        ], $this->owner);

        // Attempting to create another bill with same vendor_invoice_ref for the same vendor
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson("/api/v1/organizations/{$this->org->id}/bills", [
                'vendor_id' => $this->vendor->id,
                'bill_date' => '2025-08-16',
                'due_date' => '2025-09-15',
                'vendor_invoice_ref' => 'INV-SAME-REF',
                'lines' => [
                    [
                        'expense_account_id' => $this->expenseAccount->id,
                        'description' => 'Duplicate attempt',
                        'quantity' => 1,
                        'unit_price' => 2000.00,
                    ],
                ],
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'BILL_CREATION_FAILED');
    }

    public function test_tenant_isolation_prevents_access_to_other_org_vendors(): void
    {
        $otherUser = User::factory()->create(['email' => 'other@corp.pk']);
        $otherToken = $otherUser->createToken('other-token')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$otherToken}")
            ->getJson("/api/v1/organizations/{$this->org->id}/vendors");

        $response->assertStatus(404);
    }
}
