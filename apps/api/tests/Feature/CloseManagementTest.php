<?php

namespace Tests\Feature;

use App\Domain\Accounting\ChartOfAccounts\Models\Account;
use App\Domain\Accounting\ChartOfAccounts\Templates\PakistanSmeChartTemplate;
use App\Domain\Accounting\Period\Models\AccountingPeriod;
use App\Domain\Accounting\Period\Services\PeriodManager;
use App\Domain\Banking\Models\BankAccount;
use App\Domain\Banking\Models\BankTransaction;
use App\Domain\Close\Models\CloseCycle;
use App\Domain\Close\Models\CloseTask;
use App\Domain\Close\Models\FixedAsset;
use App\Domain\Organization\Models\Organization;
use App\Domain\Sales\Models\Customer;
use App\Domain\Sales\Models\SalesInvoice;
use App\Domain\Sales\Services\InvoiceService;
use App\Models\User;
use Database\Seeders\AccountTypeSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CloseManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Organization $org;
    private string $ownerToken;
    private AccountingPeriod $periodJuly;
    private AccountingPeriod $periodAugust;
    private Account $assetAccount;
    private Account $accumDeprAccount;
    private Account $deprExpenseAccount;
    private Account $revenueAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);
        $this->seed(AccountTypeSeeder::class);

        $this->owner = User::factory()->create([
            'name' => 'Finance Controller Tariq',
            'email' => 'controller@enterprise.pk',
        ]);
        $this->ownerToken = $this->owner->createToken('owner')->plainTextToken;

        $this->org = Organization::create([
            'name' => 'Crescent Manufacturing',
            'legal_name' => 'Crescent Manufacturing (Pvt) Ltd',
            'base_currency' => 'PKR',
            'fiscal_year_start_month' => 7,
        ]);
        $this->org->users()->attach($this->owner->id, ['role' => 'owner', 'is_default' => true]);

        PakistanSmeChartTemplate::seedForOrganization($this->org);
        app(PeriodManager::class)->generateFiscalYear($this->org, 2025);

        $periods = AccountingPeriod::where('organization_id', $this->org->id)->orderBy('start_date')->get();
        $this->periodJuly = $periods[0];
        $this->periodAugust = $periods[1];

        // Chart accounts
        $this->assetAccount = Account::where('organization_id', $this->org->id)->where('code', '1510')->firstOrFail(); // Plant & Machinery
        $this->accumDeprAccount = Account::where('organization_id', $this->org->id)->where('code', '1590')->firstOrFail(); // Accum Depr
        $this->deprExpenseAccount = Account::where('organization_id', $this->org->id)->where('code', '6070')->firstOrFail(); // Depr Exp
        $this->revenueAccount = Account::where('organization_id', $this->org->id)->where('code', '4010')->firstOrFail(); // Revenue
    }

    public function test_can_initialize_close_cycle_with_default_checklist_tasks(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->ownerToken}")
            ->getJson("/api/v1/organizations/{$this->org->id}/close-cycles/{$this->periodJuly->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'in_progress')
            ->assertJsonPath('data.total_tasks_count', 8)
            ->assertJsonPath('data.completed_tasks_count', 0)
            ->assertJsonPath('data.progress_percent', '0.00');

        $tasks = $response->json('data.tasks');
        $this->assertCount(8, $tasks);

        $taskKeys = collect($tasks)->pluck('task_key')->toArray();
        $this->assertContains('cash_reconciliation', $taskKeys);
        $this->assertContains('depreciation_entries', $taskKeys);
        $this->assertContains('flux_analysis', $taskKeys);
        $this->assertContains('period_lock', $taskKeys);
    }

    public function test_can_toggle_close_checklist_tasks_and_progress_recalculates(): void
    {
        // 1. Initialize cycle
        $cycleResponse = $this->withHeader('Authorization', "Bearer {$this->ownerToken}")
            ->getJson("/api/v1/organizations/{$this->org->id}/close-cycles/{$this->periodJuly->id}");
        $cycleResponse->assertStatus(200);

        $task1 = CloseTask::where('organization_id', $this->org->id)->where('sort_order', 1)->firstOrFail();
        $task2 = CloseTask::where('organization_id', $this->org->id)->where('sort_order', 2)->firstOrFail();

        // 2. Complete task 1
        $res1 = $this->withHeader('Authorization', "Bearer {$this->ownerToken}")
            ->postJson("/api/v1/organizations/{$this->org->id}/close-cycles/{$this->periodJuly->id}/tasks/{$task1->id}/toggle", [
                'completed' => true,
            ]);

        $res1->assertStatus(200)
            ->assertJsonPath('data.is_completed', true)
            ->assertJsonPath('meta.cycle_progress', 12.5);

        // 3. Complete task 2
        $res2 = $this->withHeader('Authorization', "Bearer {$this->ownerToken}")
            ->postJson("/api/v1/organizations/{$this->org->id}/close-cycles/{$this->periodJuly->id}/tasks/{$task2->id}/toggle", [
                'completed' => true,
            ]);

        $res2->assertStatus(200)
            ->assertJsonPath('data.is_completed', true)
            ->assertJsonPath('meta.cycle_progress', 25);

        $this->assertDatabaseHas('audit_events', [
            'organization_id' => $this->org->id,
            'event' => 'close_task:updated',
            'auditable_id' => $task1->id,
        ]);
    }

    public function test_can_register_fixed_asset_and_run_automated_depreciation_routine(): void
    {
        // 1. Create Fixed Asset (Cost PKR 600,000, 60 months life = PKR 10,000 monthly)
        $assetResponse = $this->withHeader('Authorization', "Bearer {$this->ownerToken}")
            ->postJson("/api/v1/organizations/{$this->org->id}/fixed-assets", [
                'name' => 'High-Capacity CNC Lathe Machine',
                'asset_number' => 'FA-CNC-2025',
                'asset_account_id' => $this->assetAccount->id,
                'accumulated_depreciation_account_id' => $this->accumDeprAccount->id,
                'depreciation_expense_account_id' => $this->deprExpenseAccount->id,
                'purchase_date' => '2025-07-01',
                'purchase_cost' => 600000,
                'salvage_value' => 0,
                'useful_life_months' => 60,
            ]);

        $assetResponse->assertStatus(201)
            ->assertJsonPath('data.monthly_depreciation', '10000.0000')
            ->assertJsonPath('data.status', 'active');

        // 2. Run monthly depreciation routine for July
        $deprResponse = $this->withHeader('Authorization', "Bearer {$this->ownerToken}")
            ->postJson("/api/v1/organizations/{$this->org->id}/close-cycles/{$this->periodJuly->id}/depreciation");

        $deprResponse->assertStatus(200)
            ->assertJsonPath('data.depreciation_posted', true)
            ->assertJsonPath('data.total_amount', 10000)
            ->assertJsonPath('data.assets_count', 1);

        $journalId = $deprResponse->json('data.journal_entry_id');
        $this->assertNotNull($journalId);

        // Verify balanced journal lines in General Ledger
        $this->assertDatabaseHas('journal_lines', [
            'organization_id' => $this->org->id,
            'journal_entry_id' => $journalId,
            'account_id' => $this->deprExpenseAccount->id,
            'debit' => 10000,
        ]);

        $this->assertDatabaseHas('journal_lines', [
            'organization_id' => $this->org->id,
            'journal_entry_id' => $journalId,
            'account_id' => $this->accumDeprAccount->id,
            'credit' => 10000,
        ]);

        // Verify task 'depreciation_entries' was automatically marked complete
        $task = CloseTask::where('organization_id', $this->org->id)
            ->where('task_key', 'depreciation_entries')
            ->first();

        $this->assertNotNull($task);
        $this->assertTrue($task->is_completed);
    }

    public function test_flux_analysis_identifies_period_over_period_variances(): void
    {
        $customer = Customer::create([
            'organization_id' => $this->org->id,
            'name' => 'National Traders',
            'currency' => 'PKR',
        ]);

        $invoiceService = app(InvoiceService::class);

        // July Invoice: PKR 50,000 revenue
        $invJuly = $invoiceService->createInvoice($this->org, [
            'customer_id' => $customer->id,
            'issue_date' => '2025-07-15',
            'lines' => [
                [
                    'revenue_account_id' => $this->revenueAccount->id,
                    'description' => 'July Production Run',
                    'quantity' => 1,
                    'unit_price' => 50000,
                    'tax_rate' => 0,
                ],
            ],
        ], $this->owner);
        $invoiceService->postInvoice($invJuly, $this->owner);

        // August Invoice: PKR 120,000 revenue (+140% surge)
        $invAug = $invoiceService->createInvoice($this->org, [
            'customer_id' => $customer->id,
            'issue_date' => '2025-08-15',
            'lines' => [
                [
                    'revenue_account_id' => $this->revenueAccount->id,
                    'description' => 'August Surge Production',
                    'quantity' => 1,
                    'unit_price' => 120000,
                    'tax_rate' => 0,
                ],
            ],
        ], $this->owner);
        $invoiceService->postInvoice($invAug, $this->owner);

        // Run Flux Analysis on August versus July
        $fluxResponse = $this->withHeader('Authorization', "Bearer {$this->ownerToken}")
            ->getJson("/api/v1/organizations/{$this->org->id}/close-cycles/{$this->periodAugust->id}/flux-analysis?prior_period_id={$this->periodJuly->id}");

        $fluxResponse->assertStatus(200)
            ->assertJsonPath('data.current_period.name', $this->periodAugust->name)
            ->assertJsonPath('data.prior_period.name', $this->periodJuly->name);

        $items = collect($fluxResponse->json('data.items'));
        $revenueItem = $items->firstWhere('account_code', '4010');

        $this->assertNotNull($revenueItem);
        $this->assertEquals(50000, $revenueItem['prior_balance']);
        $this->assertEquals(120000, $revenueItem['current_balance']);
        $this->assertEquals(70000, $revenueItem['dollar_change']);
        $this->assertEquals(140, $revenueItem['percent_change']);
        $this->assertTrue($revenueItem['is_significant']);
        $this->assertNotEmpty($revenueItem['commentary']);
    }

    public function test_pre_close_readiness_detects_unreconciled_or_draft_blockers(): void
    {
        // 1. Create an unreconciled bank transaction
        $bankAccount = BankAccount::create([
            'organization_id' => $this->org->id,
            'account_id' => Account::where('organization_id', $this->org->id)->where('code', '1020')->first()->id,
            'bank_name' => 'Habib Bank Limited',
            'account_title' => 'Habib Bank Limited Main',
            'account_number' => 'PK-HBL-9999',
            'currency' => 'PKR',
        ]);

        BankTransaction::create([
            'organization_id' => $this->org->id,
            'bank_account_id' => $bankAccount->id,
            'transaction_date' => '2025-07-20',
            'amount' => 25000.0000,
            'type' => 'credit',
            'description' => 'Unreconciled Supplier Transfer',
            'reconciliation_status' => 'unreconciled',
            'fingerprint' => hash('sha256', 'tx-test-close-1'),
        ]);

        // 2. Create a draft invoice
        $customer = Customer::create([
            'organization_id' => $this->org->id,
            'name' => 'Pending Client',
            'currency' => 'PKR',
        ]);

        SalesInvoice::create([
            'organization_id' => $this->org->id,
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-2025-DRAFT-1',
            'issue_date' => '2025-07-22',
            'due_date' => '2025-08-22',
            'status' => 'draft',
            'currency' => 'PKR',
            'subtotal' => 15000,
            'total_amount' => 15000,
        ]);

        // 3. Check readiness
        $response = $this->withHeader('Authorization', "Bearer {$this->ownerToken}")
            ->getJson("/api/v1/organizations/{$this->org->id}/close-cycles/{$this->periodJuly->id}/readiness");

        $response->assertStatus(200)
            ->assertJsonPath('data.can_close', false)
            ->assertJsonPath('data.unreconciled_transactions', 1)
            ->assertJsonPath('data.draft_invoices', 1);

        $blockers = $response->json('data.blockers');
        $this->assertNotEmpty($blockers);
        $this->assertLessThan(100, $response->json('data.readiness_score'));
    }
}
