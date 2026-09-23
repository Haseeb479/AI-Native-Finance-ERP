<?php

namespace Tests\Feature;

use App\Domain\Accounting\ChartOfAccounts\Templates\PakistanSmeChartTemplate;
use App\Domain\Accounting\Period\Services\PeriodManager;
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

class ApprovalWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $staff;
    private Organization $org;
    private string $ownerToken;
    private string $staffToken;
    private Customer $customer;
    private Vendor $vendor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);
        $this->seed(AccountTypeSeeder::class);

        $this->owner = User::factory()->create([
            'name' => 'Tariq Mehmood',
            'email' => 'tariq@enterprise.pk',
        ]);
        $this->ownerToken = $this->owner->createToken('owner')->plainTextToken;

        $this->staff = User::factory()->create([
            'name' => 'Bilal Junior',
            'email' => 'bilal@enterprise.pk',
        ]);
        $this->staffToken = $this->staff->createToken('staff')->plainTextToken;

        $this->org = Organization::create([
            'name' => 'Mehmood Enterprises',
            'legal_name' => 'Mehmood Enterprises (Pvt) Ltd',
            'base_currency' => 'PKR',
            'fiscal_year_start_month' => 7,
        ]);
        $this->org->users()->attach($this->owner->id, ['role' => 'owner', 'is_default' => true]);
        $this->org->users()->attach($this->staff->id, ['role' => 'staff', 'is_default' => true]);

        PakistanSmeChartTemplate::seedForOrganization($this->org);
        app(PeriodManager::class)->generateFiscalYear($this->org, 2025);

        $this->customer = Customer::create([
            'organization_id' => $this->org->id,
            'name' => 'Apex Retailers',
            'email' => 'finance@apex.pk',
            'currency' => 'PKR',
            'created_by' => $this->owner->id,
        ]);

        $this->vendor = Vendor::create([
            'organization_id' => $this->org->id,
            'name' => 'Raw Materials Ltd',
            'email' => 'orders@rawmaterials.pk',
            'currency' => 'PKR',
            'created_by' => $this->owner->id,
        ]);
    }

    public function test_sales_invoice_approval_lifecycle(): void
    {
        // 1. Create Draft invoice
        $invoice = SalesInvoice::create([
            'organization_id' => $this->org->id,
            'customer_id' => $this->customer->id,
            'invoice_number' => 'INV-2025-99991',
            'issue_date' => '2025-08-15',
            'due_date' => '2025-09-15',
            'status' => 'draft',
            'currency' => 'PKR',
            'exchange_rate' => 1,
            'subtotal' => 100000,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'total_amount' => 100000,
            'amount_paid' => 0,
            'created_by' => $this->staff->id,
        ]);

        // 2. Submit for approval
        $submitRes = $this->withHeader('Authorization', "Bearer {$this->staffToken}")
            ->postJson("/api/v1/organizations/{$this->org->id}/invoices/{$invoice->id}/submit");

        $submitRes->assertStatus(200)
            ->assertJsonPath('data.status', 'pending_approval');

        $this->assertTrue($invoice->fresh()->isPendingApproval());

        // 3. Staff cannot approve (RBAC guard)
        \Laravel\Sanctum\Sanctum::actingAs($this->staff);
        $unauthRes = $this->postJson("/api/v1/organizations/{$this->org->id}/invoices/{$invoice->id}/approve");

        $unauthRes->assertStatus(403);

        // 4. Owner approves
        \Laravel\Sanctum\Sanctum::actingAs($this->owner);
        $approveRes = $this->postJson("/api/v1/organizations/{$this->org->id}/invoices/{$invoice->id}/approve");

        $approveRes->assertStatus(200)
            ->assertJsonPath('data.status', 'approved');

        $invoice->refresh();
        $this->assertTrue($invoice->isApproved());
        $this->assertEquals($this->owner->id, $invoice->approved_by);
        $this->assertNotNull($invoice->approved_at);
    }

    public function test_sales_invoice_rejection_workflow(): void
    {
        $invoice = SalesInvoice::create([
            'organization_id' => $this->org->id,
            'customer_id' => $this->customer->id,
            'invoice_number' => 'INV-2025-99992',
            'issue_date' => '2025-08-15',
            'due_date' => '2025-09-15',
            'status' => 'pending_approval',
            'currency' => 'PKR',
            'exchange_rate' => 1,
            'subtotal' => 50000,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'total_amount' => 50000,
            'amount_paid' => 0,
            'created_by' => $this->staff->id,
        ]);

        // Owner rejects with reason
        \Laravel\Sanctum\Sanctum::actingAs($this->owner);
        $rejectRes = $this->postJson("/api/v1/organizations/{$this->org->id}/invoices/{$invoice->id}/reject", [
            'reason' => 'Unit pricing does not reflect approved 10% wholesale discount.',
        ]);

        $rejectRes->assertStatus(200)
            ->assertJsonPath('data.status', 'rejected');

        $invoice->refresh();
        $this->assertTrue($invoice->isRejected());
        $this->assertEquals($this->owner->id, $invoice->rejected_by);
        $this->assertStringContainsString('wholesale discount', $invoice->rejection_reason);

        // Staff can resubmit after fixing
        \Laravel\Sanctum\Sanctum::actingAs($this->staff);
        $resubmitRes = $this->postJson("/api/v1/organizations/{$this->org->id}/invoices/{$invoice->id}/submit");

        $resubmitRes->assertStatus(200)
            ->assertJsonPath('data.status', 'pending_approval');
    }

    public function test_purchase_bill_approval_lifecycle(): void
    {
        // 1. Create Draft bill
        $bill = PurchaseBill::create([
            'organization_id' => $this->org->id,
            'vendor_id' => $this->vendor->id,
            'bill_number' => 'BILL-2025-99991',
            'vendor_invoice_ref' => 'INV-RAW-101',
            'bill_date' => '2025-08-15',
            'due_date' => '2025-09-15',
            'status' => 'draft',
            'currency' => 'PKR',
            'exchange_rate' => 1,
            'subtotal' => 120000,
            'wht_rate' => 0,
            'wht_amount' => 0,
            'tax_amount' => 0,
            'total_amount' => 120000,
            'net_payable' => 120000,
            'amount_paid' => 0,
            'created_by' => $this->staff->id,
        ]);

        // 2. Submit for approval
        \Laravel\Sanctum\Sanctum::actingAs($this->staff);
        $this->postJson("/api/v1/organizations/{$this->org->id}/bills/{$bill->id}/submit")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'pending_approval');

        // 3. Staff cannot approve
        \Laravel\Sanctum\Sanctum::actingAs($this->staff);
        $this->postJson("/api/v1/organizations/{$this->org->id}/bills/{$bill->id}/approve")
            ->assertStatus(403);

        // 4. Owner approves
        \Laravel\Sanctum\Sanctum::actingAs($this->owner);
        $this->postJson("/api/v1/organizations/{$this->org->id}/bills/{$bill->id}/approve")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'approved');

        $bill->refresh();
        $this->assertTrue($bill->isApproved());
        $this->assertEquals($this->owner->id, $bill->approved_by);
        $this->assertNotNull($bill->approved_at);
    }
}
