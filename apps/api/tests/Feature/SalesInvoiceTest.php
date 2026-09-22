<?php

namespace Tests\Feature;

use App\Domain\Accounting\ChartOfAccounts\Models\Account;
use App\Domain\Accounting\ChartOfAccounts\Templates\PakistanSmeChartTemplate;
use App\Domain\Accounting\Period\Services\PeriodManager;
use App\Domain\Organization\Models\Organization;
use App\Domain\Sales\Models\Customer;
use App\Domain\Sales\Models\SalesInvoice;
use App\Domain\Sales\Services\InvoiceService;
use App\Models\User;
use Database\Seeders\AccountTypeSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesInvoiceTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Organization $org;
    private string $token;
    private Customer $customer;
    private Account $revenueAccount;
    private Account $arAccount;
    private Account $taxAccount;
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

        // Seed COA & Fiscal Year
        PakistanSmeChartTemplate::seedForOrganization($this->org);
        app(PeriodManager::class)->generateFiscalYear($this->org, 2025);

        $this->revenueAccount = Account::where('organization_id', $this->org->id)->where('code', '4010')->firstOrFail();
        $this->arAccount = Account::where('organization_id', $this->org->id)->where('code', '1030')->firstOrFail();
        $this->taxAccount = Account::where('organization_id', $this->org->id)->where('code', '2020')->firstOrFail();
        $this->bankAccount = Account::where('organization_id', $this->org->id)->where('code', '1020')->firstOrFail();

        $this->customer = Customer::create([
            'organization_id' => $this->org->id,
            'name' => 'Al-Madina Hypermarket',
            'legal_name' => 'Al-Madina Retailers (Pvt) Ltd',
            'ntn' => '1234567-8',
            'strn' => '17-00-1234-567-89',
            'city' => 'Karachi',
            'payment_terms_days' => 30,
        ]);
    }

    public function test_can_create_customer_with_pakistan_tax_details(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson("/api/v1/organizations/{$this->org->id}/customers", [
                'name' => 'Metro Cash & Carry Partner',
                'legal_name' => 'Metro Habib Pakistan',
                'ntn' => '9876543-2',
                'strn' => '17-12-9876-543-21',
                'city' => 'Lahore',
                'province' => 'Punjab',
                'payment_terms_days' => 45,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.name', 'Metro Cash & Carry Partner')
            ->assertJsonPath('data.ntn', '9876543-2');

        $this->assertDatabaseHas('customers', [
            'organization_id' => $this->org->id,
            'name' => 'Metro Cash & Carry Partner',
            'ntn' => '9876543-2',
        ]);
    }

    public function test_can_create_draft_sales_invoice_with_pakistan_sales_tax(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson("/api/v1/organizations/{$this->org->id}/invoices", [
                'customer_id' => $this->customer->id,
                'issue_date' => '2025-08-10',
                'lines' => [
                    [
                        'revenue_account_id' => $this->revenueAccount->id,
                        'description' => 'FMCG Packaged Goods Wholesale Lot',
                        'quantity' => 10,
                        'unit_price' => 10000.00,
                        'tax_rate' => 18.00, // Standard 18% Pakistan Sales Tax
                    ],
                ],
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.invoice_number', 'INV-2025-00001')
            ->assertJsonPath('data.subtotal', '100000.0000')
            ->assertJsonPath('data.tax_amount', '18000.0000')
            ->assertJsonPath('data.total_amount', '118000.0000');

        $this->assertDatabaseHas('sales_invoices', [
            'organization_id' => $this->org->id,
            'invoice_number' => 'INV-2025-00001',
            'status' => 'draft',
        ]);
    }

    public function test_posting_invoice_automatically_creates_and_posts_balanced_gl_journal_entry(): void
    {
        $invoiceService = app(InvoiceService::class);

        $draft = $invoiceService->createInvoice($this->org, [
            'customer_id' => $this->customer->id,
            'issue_date' => '2025-08-10',
            'lines' => [
                [
                    'revenue_account_id' => $this->revenueAccount->id,
                    'description' => 'Consulting Services',
                    'quantity' => 1,
                    'unit_price' => 200000.00,
                    'tax_rate' => 15.00, // 15% PRA Services Tax
                ],
            ],
        ], $this->owner);

        $this->assertEquals(200000.00, (float) $draft->subtotal);
        $this->assertEquals(30000.00, (float) $draft->tax_amount);
        $this->assertEquals(230000.00, (float) $draft->total_amount);

        // Post the invoice
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson("/api/v1/organizations/{$this->org->id}/invoices/{$draft->id}/post");

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'sent');

        $draft->refresh();
        $this->assertNotNull($draft->journal_entry_id);
        $this->assertEquals('sent', $draft->status);

        // Verify the linked General Ledger Journal Entry
        $journal = $draft->journalEntry;
        $this->assertNotNull($journal);
        $this->assertTrue($journal->isPosted());
        $this->assertTrue($journal->isBalanced());

        // Total Debit == Total Credit == 230,000
        $this->assertEquals(230000.00, $journal->totalDebit());
        $this->assertEquals(230000.00, $journal->totalCredit());

        // Verify journal lines
        $arLine = $journal->lines->firstWhere('account_id', $this->arAccount->id);
        $revLine = $journal->lines->firstWhere('account_id', $this->revenueAccount->id);
        $taxLine = $journal->lines->firstWhere('account_id', $this->taxAccount->id);

        $this->assertEquals(230000.00, (float) $arLine->debit);
        $this->assertEquals(200000.00, (float) $revLine->credit);
        $this->assertEquals(30000.00, (float) $taxLine->credit);
    }

    public function test_recording_partial_and_full_customer_payments(): void
    {
        $invoiceService = app(InvoiceService::class);

        $invoice = $invoiceService->createInvoice($this->org, [
            'customer_id' => $this->customer->id,
            'issue_date' => '2025-08-10',
            'lines' => [
                [
                    'revenue_account_id' => $this->revenueAccount->id,
                    'description' => 'Goods Supply',
                    'quantity' => 1,
                    'unit_price' => 100000.00,
                    'tax_rate' => 0.00,
                ],
            ],
        ], $this->owner);

        $invoice = $invoiceService->postInvoice($invoice, $this->owner);
        $this->assertEquals(100000.00, $invoice->balanceDue());

        // 1. Partial Payment of PKR 40,000 via API
        $response1 = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson("/api/v1/organizations/{$this->org->id}/invoices/{$invoice->id}/payments", [
                'amount' => 40000.00,
                'reference' => 'Bank Transfer - Chq #98124',
            ]);

        $response1->assertStatus(200)
            ->assertJsonPath('data.status', 'partial')
            ->assertJsonPath('data.amount_paid', '40000.0000')
            ->assertJsonPath('data.balance_due', 60000);

        // 2. Full Remaining Payment of PKR 60,000 via API
        $response2 = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson("/api/v1/organizations/{$this->org->id}/invoices/{$invoice->id}/payments", [
                'amount' => 60000.00,
                'reference' => 'Online IBFT Clearance',
            ]);

        $response2->assertStatus(200)
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.amount_paid', '100000.0000')
            ->assertJsonPath('data.balance_due', 0);
    }

    public function test_cannot_record_payment_exceeding_balance(): void
    {
        $invoiceService = app(InvoiceService::class);

        $invoice = $invoiceService->createInvoice($this->org, [
            'customer_id' => $this->customer->id,
            'issue_date' => '2025-08-10',
            'lines' => [
                [
                    'revenue_account_id' => $this->revenueAccount->id,
                    'description' => 'Small Service',
                    'quantity' => 1,
                    'unit_price' => 10000.00,
                ],
            ],
        ], $this->owner);

        $invoiceService->postInvoice($invoice, $this->owner);

        // Attempt to pay 15,000 on a 10,000 invoice
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson("/api/v1/organizations/{$this->org->id}/invoices/{$invoice->id}/payments", [
                'amount' => 15000.00,
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'PAYMENT_RECORD_FAILED');
    }

    public function test_tenant_isolation_prevents_viewing_customers_of_another_organization(): void
    {
        $intruder = User::factory()->create(['email' => 'intruder@other.pk']);
        $intruderToken = $intruder->createToken('intruder-token')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$intruderToken}")
            ->getJson("/api/v1/organizations/{$this->org->id}/customers");

        $response->assertStatus(404);
    }
}
