<?php

namespace Tests\Feature;

use App\Domain\Accounting\ChartOfAccounts\Models\Account;
use App\Domain\Accounting\ChartOfAccounts\Templates\PakistanSmeChartTemplate;
use App\Domain\Accounting\Period\Services\PeriodManager;
use App\Domain\Organization\Models\Organization;
use App\Domain\Sales\Models\Customer;
use App\Domain\Sales\Models\SalesInvoice;
use App\Domain\Sales\Services\InvoiceService;
use App\Domain\Taxation\Pakistan\Services\FbrInvoiceService;
use App\Models\User;
use Database\Seeders\AccountTypeSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PakistanLocalizationTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Organization $org;
    private string $ownerToken;
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);
        $this->seed(AccountTypeSeeder::class);

        $this->owner = User::factory()->create([
            'name' => 'FBR Tax Compliance Officer',
            'email' => 'tax@pakistanerp.pk',
        ]);
        $this->ownerToken = $this->owner->createToken('owner')->plainTextToken;

        $this->org = Organization::create([
            'name' => 'Indus Logistics Pakistan',
            'legal_name' => 'Indus Logistics Pakistan (Pvt) Ltd',
            'ntn' => '8765432-1',
            'strn' => '1234567890123',
            'base_currency' => 'PKR',
            'fiscal_year_start_month' => 7,
        ]);
        $this->org->users()->attach($this->owner->id, ['role' => 'owner', 'is_default' => true]);

        PakistanSmeChartTemplate::seedForOrganization($this->org);
        app(PeriodManager::class)->generateFiscalYear($this->org, 2025);

        $this->customer = Customer::create([
            'organization_id' => $this->org->id,
            'name' => 'Karachi Trading House',
            'ntn' => '2345678-9',
            'strn' => '3210987654321',
            'phone' => '+92-300-1234567',
            'email' => 'accounts@karachitrading.pk',
            'currency' => 'PKR',
        ]);
    }

    public function test_pakistan_tax_id_validation_service(): void
    {
        // 1. Valid NTN
        $ntnResponse = $this->withHeader('Authorization', "Bearer {$this->ownerToken}")
            ->postJson("/api/v1/organizations/{$this->org->id}/taxation/pakistan/validate-tax-id", [
                'type' => 'ntn',
                'tax_id' => '1234567-8',
            ]);

        $ntnResponse->assertStatus(200)
            ->assertJsonPath('data.is_valid', true)
            ->assertJsonPath('data.type', 'NTN')
            ->assertJsonPath('data.clean', '12345678');

        // 2. Valid STRN
        $strnResponse = $this->withHeader('Authorization', "Bearer {$this->ownerToken}")
            ->postJson("/api/v1/organizations/{$this->org->id}/taxation/pakistan/validate-tax-id", [
                'type' => 'strn',
                'tax_id' => '1234567890123',
            ]);

        $strnResponse->assertStatus(200)
            ->assertJsonPath('data.is_valid', true)
            ->assertJsonPath('data.type', 'STRN');

        // 3. Valid CNIC
        $cnicResponse = $this->withHeader('Authorization', "Bearer {$this->ownerToken}")
            ->postJson("/api/v1/organizations/{$this->org->id}/taxation/pakistan/validate-tax-id", [
                'type' => 'cnic',
                'tax_id' => '42101-1234567-1',
            ]);

        $cnicResponse->assertStatus(200)
            ->assertJsonPath('data.is_valid', true)
            ->assertJsonPath('data.formatted', '42101-1234567-1');
    }

    public function test_fbr_digital_invoice_fiscalization_lifecycle(): void
    {
        $revenueAccount = Account::where('organization_id', $this->org->id)
            ->where('code', '4010')
            ->first();

        // 1. Create Invoice with 18% Sales Tax (PKR 10,000 subtotal + PKR 1,800 tax = PKR 11,800 total)
        $invoiceResponse = $this->withHeader('Authorization', "Bearer {$this->ownerToken}")
            ->postJson("/api/v1/organizations/{$this->org->id}/invoices", [
                'customer_id' => $this->customer->id,
                'issue_date' => '2025-09-01',
                'due_date' => '2025-09-30',
                'lines' => [
                    [
                        'revenue_account_id' => $revenueAccount->id,
                        'description' => 'IT Consulting and Software Support',
                        'quantity' => 1,
                        'unit_price' => 10000,
                        'tax_rate' => 18,
                    ],
                ],
            ]);

        $invoiceResponse->assertStatus(201);
        $invoiceId = $invoiceResponse->json('data.id');

        // 2. Post invoice
        $this->withHeader('Authorization', "Bearer {$this->ownerToken}")
            ->postJson("/api/v1/organizations/{$this->org->id}/invoices/{$invoiceId}/post")
            ->assertStatus(200);

        // 3. Fiscalize with FBR Digital Invoicing
        $fiscalizeResponse = $this->withHeader('Authorization', "Bearer {$this->ownerToken}")
            ->postJson("/api/v1/organizations/{$this->org->id}/invoices/{$invoiceId}/fbr-fiscalize", [
                'pos_id' => '100101',
            ]);

        $fiscalizeResponse->assertStatus(200)
            ->assertJsonPath('data.fbr_status', 'fiscalized')
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'invoice_number',
                    'fbr_invoice_number',
                    'fbr_status',
                    'fbr_fiscalized_at',
                    'fbr_qr_code',
                    'fbr_response_data',
                    'total_amount',
                    'tax_amount',
                ],
            ]);

        $fbrNo = $fiscalizeResponse->json('data.fbr_invoice_number');
        $this->assertNotEmpty($fbrNo);
        $this->assertStringStartsWith('100101', $fbrNo);

        $qrCode = $fiscalizeResponse->json('data.fbr_qr_code');
        $this->assertStringContainsString("FBR_INV:{$fbrNo}", $qrCode);
        $this->assertStringContainsString('AMT:11800.00', $qrCode);
        $this->assertStringContainsString('TAX:1800.00', $qrCode);
        $this->assertStringContainsString('STATUS:FISCALIZED', $qrCode);

        // Assert audit trail captured fiscalization
        $this->assertDatabaseHas('audit_events', [
            'organization_id' => $this->org->id,
            'event' => 'invoice:fbr_fiscalized',
            'auditable_id' => $invoiceId,
        ]);

        // 4. Duplicate fiscalization rejected
        $duplicateResponse = $this->withHeader('Authorization', "Bearer {$this->ownerToken}")
            ->postJson("/api/v1/organizations/{$this->org->id}/invoices/{$invoiceId}/fbr-fiscalize");

        $duplicateResponse->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'FBR_FISCALIZE_FAILED');

        // 5. Query QR Code endpoint
        $qrResponse = $this->withHeader('Authorization', "Bearer {$this->ownerToken}")
            ->getJson("/api/v1/organizations/{$this->org->id}/invoices/{$invoiceId}/fbr-qr");

        $qrResponse->assertStatus(200)
            ->assertJsonPath('data.fbr_status', 'fiscalized')
            ->assertJsonPath('data.fbr_invoice_number', $fbrNo)
            ->assertJsonPath('data.total_amount', 11800)
            ->assertJsonPath('data.tax_amount', 1800);

        // 6. Tax & Fiscalization Summary
        $summaryResponse = $this->withHeader('Authorization', "Bearer {$this->ownerToken}")
            ->getJson("/api/v1/organizations/{$this->org->id}/taxation/pakistan/summary");

        $summaryResponse->assertStatus(200)
            ->assertJsonPath('data.fiscalized_invoices', 1)
            ->assertJsonPath('data.standard_sales_tax_rate', 18)
            ->assertJsonPath('data.total_sales_tax_collected', 1800)
            ->assertJsonPath('data.total_taxable_sales', 10000)
            ->assertJsonPath('data.total_gross_revenue', 11800);
    }
}
