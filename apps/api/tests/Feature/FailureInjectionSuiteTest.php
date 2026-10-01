<?php

namespace Tests\Feature;

use App\Domain\Accounting\ChartOfAccounts\Templates\PakistanSmeChartTemplate;
use App\Domain\Accounting\Period\Services\PeriodManager;
use App\Domain\Organization\Models\Organization;
use App\Domain\Sales\Models\Customer;
use App\Domain\Sales\Models\SalesInvoice;
use App\Domain\Shared\Outbox\Models\OutboxEvent;
use App\Domain\Shared\Outbox\Services\OutboxService;
use App\Models\User;
use Database\Seeders\AccountTypeSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FailureInjectionSuiteTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private User $owner;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);
        $this->seed(AccountTypeSeeder::class);

        $this->owner = User::factory()->create([
            'name' => 'Resilience Engineer',
            'email' => 'resilience@enterprise.test',
        ]);
        $this->token = $this->owner->createToken('test')->plainTextToken;

        $this->org = Organization::create([
            'name' => 'Resilience Corp',
            'legal_name' => 'Resilience Corp (Pvt) Ltd',
            'base_currency' => 'PKR',
            'fiscal_year_start_month' => 7,
        ]);
        $this->org->users()->attach($this->owner->id, ['role' => 'owner', 'is_default' => true]);

        PakistanSmeChartTemplate::seedForOrganization($this->org);
        app(PeriodManager::class)->generateFiscalYear($this->org, 2025);
    }

    public function test_ai_gateway_timeout_failure_handles_gracefully(): void
    {
        // Simulate AI service network timeout / crash
        Http::fake([
            '*copilot/qa*' => Http::response(null, 504),
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson("/api/v1/organizations/{$this->org->id}/ai/copilot/qa", [
                'query' => 'What is our current cash position?',
            ]);

        $response->assertStatus(502)
            ->assertJsonStructure([
                'errors' => [
                    ['code', 'message'],
                ],
            ]);
    }

    public function test_fbr_fiscalization_failure_preserves_invoice_integrity(): void
    {
        $customer = Customer::create([
            'organization_id' => $this->org->id,
            'name' => 'Test Customer',
        ]);

        $invoice = SalesInvoice::create([
            'organization_id' => $this->org->id,
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-FAIL-001',
            'issue_date' => '2025-07-15',
            'due_date' => '2025-08-15',
            'currency' => 'PKR',
            'subtotal' => 10000.00,
            'tax_amount' => 1800.00,
            'total_amount' => 11800.00,
            'status' => 'posted',
            'fbr_status' => 'fiscalized', // already fiscalized
            'fbr_invoice_number' => 'POS001250701123456',
        ]);

        // Attempting to refiscalize an already fiscalized invoice must be rejected
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson("/api/v1/organizations/{$this->org->id}/invoices/{$invoice->id}/fbr-fiscalize");

        $response->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'FBR_FISCALIZE_FAILED');

        // Verify invoice numbers and accounting status remain intact
        $invoice->refresh();
        $this->assertEquals(11800.00, (float) $invoice->total_amount);
        $this->assertEquals('posted', $invoice->status);
    }

    public function test_outbox_worker_records_failure_and_increments_retry(): void
    {
        $event = OutboxEvent::create([
            'event_type' => 'InvoicePosted',
            'aggregate_type' => 'SalesInvoice',
            'aggregate_id' => 'inv-1234',
            'organization_id' => $this->org->id,
            'payload' => ['invoice_id' => 'inv-1234', 'total' => 50000],
            'status' => 'pending',
            'retry_count' => 0,
        ]);

        // Inject simulated processing error
        $service = app(OutboxService::class);
        $service->recordFailure($event, 'Connection refused to external webhook queue');

        $event->refresh();
        $this->assertEquals(1, $event->retry_count);
        $this->assertNotNull($event->error_message);
        $this->assertStringContainsString('Connection refused', $event->error_message);
    }
}
