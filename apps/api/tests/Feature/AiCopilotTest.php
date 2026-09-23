<?php

namespace Tests\Feature;

use App\Domain\AI\Models\AiRunLog;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Database\Seeders\AccountTypeSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AiCopilotTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);
        $this->seed(AccountTypeSeeder::class);

        $this->owner = User::factory()->create([
            'name' => 'Daniyal Riaz',
            'email' => 'daniyal@enterprisepk.com',
        ]);

        $this->org = Organization::create([
            'name' => 'Riaz Financial Corp',
            'legal_name' => 'Riaz Financial Corp (Pvt) Ltd',
            'base_currency' => 'PKR',
            'fiscal_year_start_month' => 7,
        ]);
        $this->org->users()->attach($this->owner->id, ['role' => 'owner', 'is_default' => true]);

        Sanctum::actingAs($this->owner);
    }

    public function test_copilot_financial_qa_endpoint(): void
    {
        Http::fake([
            '*copilot/qa*' => Http::response([
                'answer' => 'You currently have 16 open sales invoices pending collection totaling PKR 3,240,000.',
                'key_metrics' => [
                    'pending_invoices_count' => '16',
                    'pending_invoices_amount' => 'PKR 3,240,000',
                ],
                'suggested_actions' => [
                    'Send automated payment reminder',
                ],
                'confidence' => 0.96,
                'flagged_for_review' => false,
            ], 200),
        ]);

        $response = $this->postJson("/api/v1/organizations/{$this->org->id}/ai/copilot/qa", [
            'query' => 'Please tell me all my pending invoices',
            'financial_context' => [
                'cash_balance' => 'PKR 15,000,000',
                'pending_invoices' => 16,
            ],
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.confidence', 0.96)
            ->assertJsonPath('data.key_metrics.pending_invoices_count', '16');

        $this->assertDatabaseHas('ai_run_logs', [
            'organization_id' => $this->org->id,
            'user_id' => $this->owner->id,
            'prompt_key' => 'financial_qa',
            'status' => 'success',
        ]);
    }

    public function test_copilot_draft_journal_endpoint(): void
    {
        Http::fake([
            '*copilot/draft-journal*' => Http::response([
                'description' => 'Prepaid Office Rent Allocation',
                'lines' => [
                    [
                        'account_code' => '6020',
                        'account_name' => 'Office Rent Expense',
                        'debit' => 50000.00,
                        'credit' => 0.00,
                        'description' => 'Rent expense',
                    ],
                    [
                        'account_code' => '1060',
                        'account_name' => 'Prepayments',
                        'debit' => 0.00,
                        'credit' => 50000.00,
                        'description' => 'Prepaid asset reduction',
                    ],
                ],
                'total_debit' => 50000.00,
                'total_credit' => 50000.00,
                'is_balanced' => true,
                'explanation' => 'Debit Rent Expense, Credit Prepayments.',
            ], 200),
        ]);

        $response = $this->postJson("/api/v1/organizations/{$this->org->id}/ai/copilot/draft-journal", [
            'instruction' => 'Recognize monthly office rent allocation of PKR 50,000',
            'amount' => 50000.00,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.is_balanced', true)
            ->assertJsonPath('data.total_debit', 50000);

        $this->assertDatabaseHas('ai_run_logs', [
            'organization_id' => $this->org->id,
            'prompt_key' => 'journal_draft',
            'status' => 'success',
        ]);
    }

    public function test_copilot_explain_report_endpoint(): void
    {
        Http::fake([
            '*copilot/explain-report*' => Http::response([
                'executive_summary' => 'Net Profit for Q1 stands at PKR 350,000, driven by a 24% increase in sales.',
                'key_drivers' => [
                    [
                        'account_or_category' => 'Sales Revenue',
                        'movement_description' => 'Increased by 42%',
                        'impact_level' => 'high',
                    ],
                ],
                'risk_flags' => [],
                'recommendations' => ['Accelerate collections on AR aging'],
            ], 200),
        ]);

        $response = $this->postJson("/api/v1/organizations/{$this->org->id}/ai/copilot/explain-report", [
            'report_type' => 'pnl',
            'period_label' => 'Q1 FY 2025-2026',
            'report_data' => [
                'revenue' => 500000.0,
                'cogs' => 150000.0,
                'net_profit' => 300000.0,
            ],
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.executive_summary', 'Net Profit for Q1 stands at PKR 350,000, driven by a 24% increase in sales.');

        $this->assertDatabaseHas('ai_run_logs', [
            'organization_id' => $this->org->id,
            'prompt_key' => 'explain_report',
            'status' => 'success',
        ]);
    }

    public function test_copilot_tenant_isolation(): void
    {
        $intruder = User::factory()->create();
        Sanctum::actingAs($intruder);

        $response = $this->postJson("/api/v1/organizations/{$this->org->id}/ai/copilot/qa", [
            'query' => 'What is the cash balance?',
        ]);

        $response->assertStatus(404);
    }
}
