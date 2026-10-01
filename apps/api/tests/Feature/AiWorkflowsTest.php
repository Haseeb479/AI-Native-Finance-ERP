<?php

namespace Tests\Feature;

use App\Domain\Accounting\ChartOfAccounts\Templates\PakistanSmeChartTemplate;
use App\Domain\Accounting\Period\Models\AccountingPeriod;
use App\Domain\Accounting\Period\Services\PeriodManager;
use App\Domain\Banking\Models\BankAccount;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Database\Seeders\AccountTypeSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiWorkflowsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Organization $org;
    private string $token;
    private AccountingPeriod $period;
    private BankAccount $bankAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);
        $this->seed(AccountTypeSeeder::class);

        $this->owner = User::factory()->create([
            'name' => 'Controller User',
            'email' => 'controller@enterprise.test',
        ]);
        $this->token = $this->owner->createToken('test')->plainTextToken;

        $this->org = Organization::create([
            'name' => 'Apex Enterprises',
            'legal_name' => 'Apex Enterprises (Pvt) Ltd',
            'base_currency' => 'PKR',
            'fiscal_year_start_month' => 7,
        ]);
        $this->org->users()->attach($this->owner->id, ['role' => 'owner', 'is_default' => true]);

        PakistanSmeChartTemplate::seedForOrganization($this->org);
        app(PeriodManager::class)->generateFiscalYear($this->org, 2025);

        $this->period = AccountingPeriod::where('organization_id', $this->org->id)
            ->where('status', 'open')
            ->firstOrFail();

        $cashAccount = \App\Domain\Accounting\ChartOfAccounts\Models\Account::where('organization_id', $this->org->id)
            ->where('code', '1010')->firstOrFail();

        $this->bankAccount = BankAccount::create([
            'organization_id' => $this->org->id,
            'account_id' => $cashAccount->id,
            'account_title' => 'Meezan Bank Operations',
            'account_number' => 'PK36MEZN00001234567801',
            'bank_name' => 'Meezan Bank',
            'currency' => 'PKR',
            'current_balance' => 250000.00,
        ]);
    }

    public function test_ai_workflow_prepare_month_end_close(): void
    {
        Http::fake([
            '*copilot/workflows/prepare-close*' => Http::response([
                'readiness_status' => 'READY',
                'readiness_score_pct' => 95,
                'executive_assessment' => 'Trial balance is balanced, zero blocker exceptions.',
                'blocking_items' => [],
                'action_checklist' => ['Lock accounting period'],
            ], 200),
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson("/api/v1/organizations/{$this->org->id}/ai/workflows/prepare-close", [
                'period_id' => $this->period->id,
            ]);

        $response->assertOk()
            ->assertJsonPath('data.readiness_status', 'READY')
            ->assertJsonPath('data.readiness_score_pct', 95);
    }

    public function test_ai_workflow_unreconciled_transactions(): void
    {
        Http::fake([
            '*copilot/workflows/unreconciled-transactions*' => Http::response([
                'total_unreconciled_amount' => '15000.00',
                'total_count' => 1,
                'summary' => '1 transaction requiring review.',
                'analyzed_items' => [],
                'recommended_resolutions' => [],
            ], 200),
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson("/api/v1/organizations/{$this->org->id}/ai/workflows/unreconciled-transactions", [
                'bank_account_id' => $this->bankAccount->id,
            ]);

        $response->assertOk()
            ->assertJsonPath('data.total_unreconciled_amount', '15000.00');
    }

    public function test_ai_workflow_margin_analysis(): void
    {
        Http::fake([
            '*copilot/workflows/margin-analysis*' => Http::response([
                'gross_margin_delta_bps' => 450,
                'executive_summary' => 'Gross margin expanded by 450 bps.',
                'primary_drivers' => [],
            ], 200),
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson("/api/v1/organizations/{$this->org->id}/ai/workflows/margin-analysis", [
                'current_from' => '2025-07-01',
                'current_to' => '2025-09-30',
                'prior_from' => '2025-04-01',
                'prior_to' => '2025-06-30',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.gross_margin_delta_bps', 450);
    }

    public function test_ai_workflow_invoice_approval_queue(): void
    {
        Http::fake([
            '*copilot/workflows/invoice-approval-queue*' => Http::response([
                'total_pending_count' => 0,
                'total_pending_amount' => '0.00',
                'approval_queue' => [],
            ], 200),
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson("/api/v1/organizations/{$this->org->id}/ai/workflows/invoice-approval-queue");

        $response->assertOk()
            ->assertJsonPath('data.total_pending_count', 0);
    }

    public function test_ai_workflow_draft_reconciliation_matches(): void
    {
        Http::fake([
            '*copilot/workflows/draft-reconciliation-matches*' => Http::response([
                'proposed_matches' => [],
                'unmatched_count' => 0,
                'matching_rate_pct' => 100,
            ], 200),
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson("/api/v1/organizations/{$this->org->id}/ai/workflows/draft-reconciliation-matches", [
                'bank_account_id' => $this->bankAccount->id,
            ]);

        $response->assertOk()
            ->assertJsonPath('data.matching_rate_pct', 100);
    }

    public function test_ai_workflow_missing_vendor_documents(): void
    {
        Http::fake([
            '*copilot/workflows/missing-vendor-documents*' => Http::response([
                'missing_docs_count' => 0,
                'total_undocumented_amount' => '0.00',
                'risk_summary' => 'All vendor bills have attached tax invoices.',
            ], 200),
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson("/api/v1/organizations/{$this->org->id}/ai/workflows/missing-vendor-documents");

        $response->assertOk()
            ->assertJsonPath('data.missing_docs_count', 0);
    }

    public function test_ai_workflow_ar_collections_queue(): void
    {
        Http::fake([
            '*copilot/workflows/ar-collections-queue*' => Http::response([
                'total_overdue_amount' => '0.00',
                'total_customers_overdue' => 0,
                'high_priority_count' => 0,
                'action_queue' => [],
            ], 200),
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson("/api/v1/organizations/{$this->org->id}/ai/workflows/ar-collections-queue");

        $response->assertOk()
            ->assertJsonPath('data.total_customers_overdue', 0);
    }
}
