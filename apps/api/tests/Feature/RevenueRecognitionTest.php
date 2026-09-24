<?php

namespace Tests\Feature;

use App\Domain\Accounting\ChartOfAccounts\Models\Account;
use App\Domain\Accounting\ChartOfAccounts\Templates\PakistanSmeChartTemplate;
use App\Domain\Accounting\Period\Services\PeriodManager;
use App\Domain\Organization\Models\Organization;
use App\Domain\Revenue\Models\RevenueContract;
use App\Domain\Revenue\Models\RevenueSchedule;
use App\Domain\Revenue\Services\RevenueRecognitionService;
use App\Domain\Sales\Models\Customer;
use Database\Seeders\AccountTypeSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RevenueRecognitionTest extends TestCase
{
    use RefreshDatabase;

    private \App\Models\User $owner;
    private Organization $org;
    private string $ownerToken;
    private Customer $customer;
    private Account $deferredAccount;
    private Account $revenueAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);
        $this->seed(AccountTypeSeeder::class);

        $this->owner = \App\Models\User::factory()->create([
            'name' => 'Finance Director Bilal',
            'email' => 'bilal@revrec-test.pk',
        ]);
        $this->ownerToken = $this->owner->createToken('owner')->plainTextToken;

        $this->org = Organization::create([
            'name' => 'SaaS Innovations PK',
            'legal_name' => 'SaaS Innovations Private Limited',
            'base_currency' => 'PKR',
            'fiscal_year_start_month' => 7,
        ]);
        $this->org->users()->attach($this->owner->id, ['role' => 'owner', 'is_default' => true]);

        PakistanSmeChartTemplate::seedForOrganization($this->org);
        app(PeriodManager::class)->generateFiscalYear($this->org, 2025);

        $this->customer = Customer::create([
            'organization_id' => $this->org->id,
            'name' => 'Enterprise Client Corp',
            'email' => 'finance@enterprise.pk',
            'currency' => 'PKR',
            'payment_terms_days' => 30,
        ]);

        $this->deferredAccount = Account::withoutGlobalScopes()
            ->where('organization_id', $this->org->id)
            ->where('code', '2010') // Liability account
            ->firstOrFail();

        $this->revenueAccount = Account::withoutGlobalScopes()
            ->where('organization_id', $this->org->id)
            ->where('code', '4020') // Consulting/Service revenue
            ->firstOrFail();
    }

    public function test_can_create_annual_contract_and_generates_schedules(): void
    {
        $service = app(RevenueRecognitionService::class);

        $contract = $service->createContract($this->org, [
            'customer_id' => $this->customer->id,
            'title' => 'Annual SaaS Enterprise Subscription',
            'start_date' => '2025-07-01',
            'end_date' => '2026-06-30',
            'total_contract_value' => 1200000.00,
            'recognition_method' => 'straight_line',
            'deferred_revenue_account_id' => $this->deferredAccount->id,
            'revenue_account_id' => $this->revenueAccount->id,
        ], $this->owner);

        $this->assertNotNull($contract->id);
        $this->assertEquals(1200000.00, (float) $contract->total_contract_value);
        $this->assertEquals(12, $contract->schedules()->count());

        // Each monthly schedule should be 100,000 PKR
        $firstSchedule = $contract->schedules()->first();
        $this->assertEqualsWithDelta(100000.00, (float) $firstSchedule->amount, 0.01);
        $this->assertEquals('pending', $firstSchedule->status);
    }

    public function test_recognizing_schedule_creates_balanced_gl_journal_entry(): void
    {
        $service = app(RevenueRecognitionService::class);

        $contract = $service->createContract($this->org, [
            'customer_id' => $this->customer->id,
            'title' => 'Quarterly Retainer Contract',
            'start_date' => '2025-07-01',
            'end_date' => '2025-09-30',
            'total_contract_value' => 300000.00,
            'recognition_method' => 'straight_line',
            'deferred_revenue_account_id' => $this->deferredAccount->id,
            'revenue_account_id' => $this->revenueAccount->id,
        ], $this->owner);

        $firstSchedule = $contract->schedules()->first();
        $postedSchedule = $service->recognizeSchedule($firstSchedule, $this->owner);

        $this->assertEquals('posted', $postedSchedule->status);
        $this->assertNotNull($postedSchedule->journal_entry_id);

        // Verify Journal Entry is balanced
        $journal = $postedSchedule->journalEntry;
        $this->assertNotNull($journal);
        $this->assertTrue($journal->isBalanced());
        $this->assertEquals('posted', $journal->status);
        $this->assertEqualsWithDelta(100000.00, (float) $journal->total_amount, 0.01);

        // Verify contract recognized amount
        $contract->refresh();
        $this->assertEquals(100000.00, $contract->totalRecognized());
        $this->assertEquals(200000.00, $contract->totalRemaining());
    }

    public function test_contract_amendment_recalculates_pending_schedules(): void
    {
        $service = app(RevenueRecognitionService::class);

        $contract = $service->createContract($this->org, [
            'customer_id' => $this->customer->id,
            'title' => 'Expandable Platform Contract',
            'start_date' => '2025-07-01',
            'end_date' => '2025-09-30',
            'total_contract_value' => 300000.00,
            'deferred_revenue_account_id' => $this->deferredAccount->id,
            'revenue_account_id' => $this->revenueAccount->id,
        ], $this->owner);

        // Recognize month 1 (100,000)
        $firstSchedule = $contract->schedules()->first();
        $service->recognizeSchedule($firstSchedule, $this->owner);

        // Client upgrades contract total from 300,000 to 500,000
        $amended = $service->amendContract($contract, 500000.00, null, $this->owner);

        $this->assertEquals(500000.00, (float) $amended->total_contract_value);
        // Total recognized remains 100,000
        $this->assertEquals(100000.00, $amended->totalRecognized());
        // Remaining unearned is 400,000
        $this->assertEquals(400000.00, $amended->totalRemaining());
    }

    public function test_api_endpoints_work_with_authentication(): void
    {
        // 1. Create contract via API
        $res = $this->withHeader('Authorization', "Bearer {$this->ownerToken}")
            ->postJson("/api/v1/organizations/{$this->org->id}/revenue-contracts", [
                'customer_id' => $this->customer->id,
                'title' => 'API Subscription Contract',
                'start_date' => '2025-07-01',
                'end_date' => '2025-09-30',
                'total_contract_value' => 600000.00,
                'deferred_revenue_account_id' => $this->deferredAccount->id,
                'revenue_account_id' => $this->revenueAccount->id,
            ]);

        $res->assertStatus(201);
        $contractId = $res->json('data.id');
        $this->assertNotNull($contractId);

        // 2. Fetch contract details
        $showRes = $this->withHeader('Authorization', "Bearer {$this->ownerToken}")
            ->getJson("/api/v1/organizations/{$this->org->id}/revenue-contracts/{$contractId}");

        $showRes->assertStatus(200);
        $schedules = $showRes->json('data.schedules');
        $this->assertCount(3, $schedules);

        // 3. Recognize first schedule
        $scheduleId = $schedules[0]['id'];
        $recRes = $this->withHeader('Authorization', "Bearer {$this->ownerToken}")
            ->postJson("/api/v1/organizations/{$this->org->id}/revenue-schedules/{$scheduleId}/recognize");

        $recRes->assertStatus(200);
        $this->assertEquals('posted', $recRes->json('data.status'));
    }
}
