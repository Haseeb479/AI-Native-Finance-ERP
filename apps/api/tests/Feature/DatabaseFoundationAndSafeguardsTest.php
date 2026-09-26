<?php

namespace Tests\Feature;

use App\Domain\Accounting\ChartOfAccounts\Models\Account;
use App\Domain\Accounting\ChartOfAccounts\Templates\PakistanSmeChartTemplate;
use App\Domain\Accounting\Period\Services\PeriodManager;
use App\Domain\Accounting\Posting\Exceptions\ClosedPeriodException;
use App\Domain\Accounting\Posting\Exceptions\ControlAccountProtectedException;
use App\Domain\Accounting\Posting\Services\PostingEngine;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Database\Seeders\AccountTypeSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class DatabaseFoundationAndSafeguardsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Organization $org;
    private string $token;
    private Account $cashAccount;
    private Account $bankAccount;
    private Account $capitalAccount;
    private Account $expenseAccount;
    private Account $arAccount;
    private Account $apAccount;
    private Account $revenueAccount;
    private PostingEngine $postingEngine;
    private PeriodManager $periodManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);
        $this->seed(AccountTypeSeeder::class);

        $this->owner = User::factory()->create([
            'name' => 'Safeguard Auditor',
            'email' => 'auditor@erp-pk.com',
        ]);

        $this->token = $this->owner->createToken('test-token')->plainTextToken;

        $this->org = Organization::create([
            'name' => 'Safeguards Enterprise',
            'legal_name' => 'Safeguards Enterprise (Pvt) Ltd',
            'base_currency' => 'PKR',
            'fiscal_year_start_month' => 7,
        ]);
        $this->org->users()->attach($this->owner->id, ['role' => 'owner', 'is_default' => true]);

        PakistanSmeChartTemplate::seedForOrganization($this->org);
        $this->periodManager = app(PeriodManager::class);
        $this->periodManager->generateFiscalYear($this->org, 2025);
        $this->postingEngine = app(PostingEngine::class);

        $this->cashAccount = Account::where('organization_id', $this->org->id)->where('code', '1010')->firstOrFail();
        $this->bankAccount = Account::where('organization_id', $this->org->id)->where('code', '1020')->firstOrFail();
        $this->capitalAccount = Account::where('organization_id', $this->org->id)->where('code', '3010')->firstOrFail();
        $this->expenseAccount = Account::where('organization_id', $this->org->id)->where('code', '6020')->firstOrFail();
        $this->arAccount = Account::where('organization_id', $this->org->id)->where('code', '1030')->firstOrFail();
        $this->apAccount = Account::where('organization_id', $this->org->id)->where('code', '2010')->firstOrFail();
        $this->revenueAccount = Account::where('organization_id', $this->org->id)->where('code', '4010')->firstOrFail();
    }

    public function test_control_accounts_are_flagged_correctly_in_chart_of_accounts(): void
    {
        $this->assertTrue($this->arAccount->isControlAccount());
        $this->assertEquals('ar_control', $this->arAccount->control_type);

        $this->assertTrue($this->apAccount->isControlAccount());
        $this->assertEquals('ap_control', $this->apAccount->control_type);

        $this->assertFalse($this->cashAccount->isControlAccount());
        $this->assertFalse($this->revenueAccount->isControlAccount());
    }

    public function test_journal_line_rejects_negative_amounts(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Journal line amounts must be strictly non-negative.');

        $this->postingEngine->createDraft($this->org, [
            'entry_date' => '2025-08-01',
            'description' => 'Invalid negative line',
            'lines' => [
                ['account_id' => $this->expenseAccount->id, 'debit' => -500.00, 'credit' => 0.00],
                ['account_id' => $this->bankAccount->id, 'debit' => 0.00, 'credit' => -500.00],
            ],
        ], $this->owner);
    }

    public function test_journal_line_rejects_both_debit_and_credit_on_same_line(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Debit and credit must be mutually exclusive.');

        $this->postingEngine->createDraft($this->org, [
            'entry_date' => '2025-08-01',
            'description' => 'Simultaneous debit and credit',
            'lines' => [
                ['account_id' => $this->expenseAccount->id, 'debit' => 500.00, 'credit' => 500.00],
                ['account_id' => $this->bankAccount->id, 'debit' => 0.00, 'credit' => 500.00],
            ],
        ], $this->owner);
    }

    public function test_journal_line_rejects_zero_debit_and_zero_credit(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Journal line amount must be greater than zero.');

        $this->postingEngine->createDraft($this->org, [
            'entry_date' => '2025-08-01',
            'description' => 'Zero amount line',
            'lines' => [
                ['account_id' => $this->expenseAccount->id, 'debit' => 0.00, 'credit' => 0.00],
                ['account_id' => $this->bankAccount->id, 'debit' => 0.00, 'credit' => 500.00],
            ],
        ], $this->owner);
    }

    public function test_manual_journal_targeting_ar_control_account_is_blocked(): void
    {
        $this->expectException(ControlAccountProtectedException::class);
        $this->expectExceptionMessage('Direct manual journal posting to control account [1030 - Trade Debtors / Accounts Receivable] is prohibited');

        $this->postingEngine->createDraft($this->org, [
            'entry_date' => '2025-08-01',
            'source_type' => 'manual',
            'description' => 'Attempted direct debit to AR control account',
            'lines' => [
                ['account_id' => $this->arAccount->id, 'debit' => 15000.00, 'credit' => 0.00],
                ['account_id' => $this->revenueAccount->id, 'debit' => 0.00, 'credit' => 15000.00],
            ],
        ], $this->owner);
    }

    public function test_manual_journal_targeting_ap_control_account_is_blocked(): void
    {
        $this->expectException(ControlAccountProtectedException::class);
        $this->expectExceptionMessage('Direct manual journal posting to control account [2010 - Trade Creditors / Accounts Payable] is prohibited');

        $this->postingEngine->createDraft($this->org, [
            'entry_date' => '2025-08-01',
            'source_type' => 'manual',
            'description' => 'Attempted direct credit to AP control account',
            'lines' => [
                ['account_id' => $this->expenseAccount->id, 'debit' => 8000.00, 'credit' => 0.00],
                ['account_id' => $this->apAccount->id, 'debit' => 0.00, 'credit' => 8000.00],
            ],
        ], $this->owner);
    }

    public function test_api_blocks_manual_journal_targeting_control_account_with_422(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson("/api/v1/organizations/{$this->org->id}/journals", [
                'entry_date' => '2025-08-01',
                'description' => 'Direct API post to control account',
                'lines' => [
                    ['account_id' => $this->arAccount->id, 'debit' => 20000.00, 'credit' => 0.00],
                    ['account_id' => $this->revenueAccount->id, 'debit' => 0.00, 'credit' => 20000.00],
                ],
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'ACCOUNTING_RULE_VIOLATION');
    }

    public function test_subledger_posting_can_target_control_accounts(): void
    {
        // Subledgers (e.g. source_type = 'invoice' or 'bill') MUST be allowed to post to control accounts
        $draft = $this->postingEngine->createDraft($this->org, [
            'entry_date' => '2025-08-01',
            'source_type' => 'invoice',
            'source_id' => '00000000-0000-0000-0000-000000000001',
            'description' => 'Sales Invoice #INV-2025-001',
            'lines' => [
                ['account_id' => $this->arAccount->id, 'debit' => 50000.00, 'credit' => 0.00],
                ['account_id' => $this->revenueAccount->id, 'debit' => 0.00, 'credit' => 50000.00],
            ],
        ], $this->owner);

        $posted = $this->postingEngine->postEntry($draft, $this->owner);

        $this->assertEquals('posted', $posted->status);
        $this->assertEquals(50000.00, $posted->total_amount);
    }

    public function test_soft_close_blocks_operational_sources_but_permits_adjustments(): void
    {
        $period = $this->periodManager->getOpenPeriodForDate($this->org, '2025-08-15');
        $this->assertNotNull($period);

        // Soft-close August 2025
        $this->periodManager->softClosePeriod($period, $this->owner);
        $period->refresh();
        $this->assertTrue($period->isSoftClosed());

        // 1. Operational entry (source_type = 'invoice') must be blocked
        $operationalDraft = $this->postingEngine->createDraft($this->org, [
            'entry_date' => '2025-08-15',
            'source_type' => 'invoice',
            'accounting_period_id' => $period->id,
            'description' => 'Operational late invoice',
            'lines' => [
                ['account_id' => $this->arAccount->id, 'debit' => 10000.00, 'credit' => 0.00],
                ['account_id' => $this->revenueAccount->id, 'debit' => 0.00, 'credit' => 10000.00],
            ],
        ], $this->owner);

        try {
            $this->postingEngine->postEntry($operationalDraft, $this->owner);
            $this->fail('Expected ClosedPeriodException was not thrown for operational invoice in soft-closed period');
        } catch (ClosedPeriodException $e) {
            $this->assertStringContainsString('soft-closed', $e->getMessage());
            $this->assertStringContainsString('Operational subledger postings (invoice) are locked', $e->getMessage());
        }

        // 2. Adjusting entry (source_type = 'manual' or 'adjustment') must be allowed
        $adjustingDraft = $this->postingEngine->createDraft($this->org, [
            'entry_date' => '2025-08-15',
            'source_type' => 'manual',
            'accounting_period_id' => $period->id,
            'description' => 'Month-end accrual adjustment',
            'lines' => [
                ['account_id' => $this->expenseAccount->id, 'debit' => 2500.00, 'credit' => 0.00],
                ['account_id' => $this->bankAccount->id, 'debit' => 0.00, 'credit' => 2500.00],
            ],
        ], $this->owner);

        $postedAdjustment = $this->postingEngine->postEntry($adjustingDraft, $this->owner);
        $this->assertEquals('posted', $postedAdjustment->status);
    }

    public function test_hard_close_blocks_all_entries_including_adjustments(): void
    {
        $period = $this->periodManager->getOpenPeriodForDate($this->org, '2025-09-15');
        $this->assertNotNull($period);

        // Hard-close September 2025
        $this->periodManager->hardClosePeriod($period, $this->owner);
        $period->refresh();
        $this->assertTrue($period->isHardClosed());

        // Attempting to post an adjustment in hard-closed period must be blocked
        $adjustmentDraft = $this->postingEngine->createDraft($this->org, [
            'entry_date' => '2025-09-15',
            'source_type' => 'manual',
            'accounting_period_id' => $period->id,
            'description' => 'Attempted adjustment in hard-closed period',
            'lines' => [
                ['account_id' => $this->expenseAccount->id, 'debit' => 1000.00, 'credit' => 0.00],
                ['account_id' => $this->bankAccount->id, 'debit' => 0.00, 'credit' => 1000.00],
            ],
        ], $this->owner);

        $this->expectException(ClosedPeriodException::class);
        $this->postingEngine->postEntry($adjustmentDraft, $this->owner);
    }

    public function test_soft_close_and_hard_close_api_endpoints(): void
    {
        $period = $this->periodManager->getOpenPeriodForDate($this->org, '2025-10-15');
        $this->assertNotNull($period);

        // Soft-close via API
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson("/api/v1/organizations/{$this->org->id}/periods/{$period->id}/soft-close");

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'soft_closed');

        // Hard-close via API
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson("/api/v1/organizations/{$this->org->id}/periods/{$period->id}/hard-close");

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'hard_closed');

        // Reopen via API
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson("/api/v1/organizations/{$this->org->id}/periods/{$period->id}/reopen", [
                'reason' => 'Audit review required adjustment window',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'open');
    }
}
