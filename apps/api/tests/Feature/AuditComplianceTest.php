<?php

namespace Tests\Feature;

use App\Domain\Accounting\ChartOfAccounts\Models\Account;
use App\Domain\Accounting\ChartOfAccounts\Templates\PakistanSmeChartTemplate;
use App\Domain\Accounting\Period\Models\AccountingPeriod;
use App\Domain\Accounting\Period\Services\PeriodManager;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Organization\Models\Organization;
use App\Domain\Sales\Models\Customer;
use App\Domain\Sales\Models\SalesInvoice;
use App\Domain\Sales\Services\InvoiceService;
use App\Models\User;
use Database\Seeders\AccountTypeSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditComplianceTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $staff;
    private Organization $org;
    private string $ownerToken;
    private string $staffToken;
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);
        $this->seed(AccountTypeSeeder::class);

        $this->owner = User::factory()->create([
            'name' => 'Khurram Auditor',
            'email' => 'khurram@auditcorp.pk',
        ]);
        $this->ownerToken = $this->owner->createToken('owner')->plainTextToken;

        $this->staff = User::factory()->create([
            'name' => 'Junior Staff',
            'email' => 'junior@auditcorp.pk',
        ]);
        $this->staffToken = $this->staff->createToken('staff')->plainTextToken;

        $this->org = Organization::create([
            'name' => 'Audit Compliant ERP',
            'legal_name' => 'Audit Compliant ERP (Pvt) Ltd',
            'ntn' => '7654321-9',
            'base_currency' => 'PKR',
            'fiscal_year_start_month' => 7,
        ]);

        $this->org->users()->attach($this->owner->id, ['role' => 'owner', 'is_default' => true]);
        $this->org->users()->attach($this->staff->id, ['role' => 'staff', 'is_default' => true]);

        PakistanSmeChartTemplate::seedForOrganization($this->org);
        app(PeriodManager::class)->generateFiscalYear($this->org, 2025);

        $this->customer = Customer::create([
            'organization_id' => $this->org->id,
            'name' => 'Audited Client Corp',
            'ntn' => '1234567-8',
            'email' => 'auditclient@corp.pk',
            'currency' => 'PKR',
        ]);
    }

    public function test_audit_event_logged_when_period_closed_reopened_and_locked(): void
    {
        $period = AccountingPeriod::where('organization_id', $this->org->id)->first();
        $this->assertNotNull($period);

        // 1. Close Period
        $response = $this->withHeader('Authorization', "Bearer {$this->ownerToken}")
            ->postJson("/api/v1/organizations/{$this->org->id}/periods/{$period->id}/close");

        $response->assertStatus(200);

        $this->assertDatabaseHas('audit_events', [
            'organization_id' => $this->org->id,
            'event' => 'period:closed',
            'auditable_type' => AccountingPeriod::class,
            'auditable_id' => $period->id,
        ]);

        // 2. Reopen Period
        $reopenResponse = $this->withHeader('Authorization', "Bearer {$this->ownerToken}")
            ->postJson("/api/v1/organizations/{$this->org->id}/periods/{$period->id}/reopen", [
                'reason' => 'Quarterly audit adjustment authorized by Board',
            ]);

        $reopenResponse->assertStatus(200);

        $this->assertDatabaseHas('audit_events', [
            'organization_id' => $this->org->id,
            'event' => 'period:reopened',
            'auditable_id' => $period->id,
        ]);

        // 3. Lock Period
        $lockResponse = $this->withHeader('Authorization', "Bearer {$this->ownerToken}")
            ->postJson("/api/v1/organizations/{$this->org->id}/periods/{$period->id}/lock");

        $lockResponse->assertStatus(200);

        $this->assertDatabaseHas('audit_events', [
            'organization_id' => $this->org->id,
            'event' => 'period:locked',
            'auditable_id' => $period->id,
        ]);
    }

    public function test_audit_events_logged_for_invoice_approval_and_posting(): void
    {
        $revenueAccount = Account::where('organization_id', $this->org->id)
            ->where('code', '4010')
            ->first();

        // 1. Create Invoice
        $createResponse = $this->withHeader('Authorization', "Bearer {$this->ownerToken}")
            ->postJson("/api/v1/organizations/{$this->org->id}/invoices", [
                'customer_id' => $this->customer->id,
                'issue_date' => '2025-08-01',
                'due_date' => '2025-08-31',
                'lines' => [
                    [
                        'revenue_account_id' => $revenueAccount->id,
                        'description' => 'Annual Regulatory Audit Software License',
                        'quantity' => 1,
                        'unit_price' => 50000,
                        'tax_rate' => 18,
                    ],
                ],
            ]);

        $createResponse->assertStatus(201);
        $invoiceId = $createResponse->json('data.id');

        // 2. Submit for approval
        $this->withHeader('Authorization', "Bearer {$this->ownerToken}")
            ->postJson("/api/v1/organizations/{$this->org->id}/invoices/{$invoiceId}/submit")
            ->assertStatus(200);

        // 3. Approve Invoice
        $this->withHeader('Authorization', "Bearer {$this->ownerToken}")
            ->postJson("/api/v1/organizations/{$this->org->id}/invoices/{$invoiceId}/approve")
            ->assertStatus(200);

        $this->assertDatabaseHas('audit_events', [
            'organization_id' => $this->org->id,
            'event' => 'invoice:approved',
            'auditable_id' => $invoiceId,
        ]);

        // 4. Post Invoice to General Ledger
        $this->withHeader('Authorization', "Bearer {$this->ownerToken}")
            ->postJson("/api/v1/organizations/{$this->org->id}/invoices/{$invoiceId}/post")
            ->assertStatus(200);

        $this->assertDatabaseHas('audit_events', [
            'organization_id' => $this->org->id,
            'event' => 'invoice:posted',
            'auditable_id' => $invoiceId,
        ]);

        $this->assertDatabaseHas('audit_events', [
            'organization_id' => $this->org->id,
            'event' => 'journal:posted',
        ]);
    }

    public function test_audit_logs_query_and_export_endpoints(): void
    {
        // Generate some audit events
        $period = AccountingPeriod::where('organization_id', $this->org->id)->first();
        app(PeriodManager::class)->closePeriod($period, $this->owner);

        // Test GET audit-logs
        $response = $this->withHeader('Authorization', "Bearer {$this->ownerToken}")
            ->getJson("/api/v1/organizations/{$this->org->id}/audit-logs");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'organization_id', 'event', 'auditable_type', 'auditable_id', 'created_at'],
                ],
                'meta' => ['total', 'current_page', 'organization_id'],
                'errors',
            ]);

        // Test GET audit-logs with filter
        $filterResponse = $this->withHeader('Authorization', "Bearer {$this->ownerToken}")
            ->getJson("/api/v1/organizations/{$this->org->id}/audit-logs?event=period:closed");

        $filterResponse->assertStatus(200);
        $this->assertGreaterThanOrEqual(1, count($filterResponse->json('data')));

        // Test Export endpoint
        $exportResponse = $this->withHeader('Authorization', "Bearer {$this->ownerToken}")
            ->getJson("/api/v1/organizations/{$this->org->id}/audit-logs/export");

        $exportResponse->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'organization' => ['id', 'name', 'ntn'],
                    'exported_at',
                    'total_events',
                    'events',
                ],
            ]);

        // Test Verification endpoint
        $verifyResponse = $this->withHeader('Authorization', "Bearer {$this->ownerToken}")
            ->getJson("/api/v1/organizations/{$this->org->id}/audit-logs/verify");

        $verifyResponse->assertStatus(200)
            ->assertJsonPath('data.is_valid', true)
            ->assertJsonPath('data.status', 'VERIFIED_IMMUTABLE');
    }

    public function test_unauthorized_user_cannot_view_audit_logs(): void
    {
        \Laravel\Sanctum\Sanctum::actingAs($this->staff);

        $response = $this->withHeader('Authorization', "Bearer {$this->staffToken}")
            ->getJson("/api/v1/organizations/{$this->org->id}/audit-logs");

        $response->assertStatus(403)
            ->assertJsonPath('errors.0.code', 'FORBIDDEN');
    }
}
