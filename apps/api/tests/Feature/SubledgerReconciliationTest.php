<?php

namespace Tests\Feature;

use App\Domain\Accounting\ChartOfAccounts\Models\Account;
use App\Domain\Accounting\ChartOfAccounts\Templates\PakistanSmeChartTemplate;
use App\Domain\Accounting\Journal\Models\JournalEntry;
use App\Domain\Accounting\Period\Services\PeriodManager;
use App\Domain\Accounting\Posting\Services\PostingEngine;
use App\Domain\Accounting\Reconciliation\Services\SubledgerReconciliationService;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Database\Seeders\AccountTypeSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class SubledgerReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private User $user;
    private string $token;
    private PostingEngine $postingEngine;
    private SubledgerReconciliationService $reconciliationService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);
        $this->seed(AccountTypeSeeder::class);

        $this->user = User::factory()->create([
            'name' => 'Controller User',
            'email' => 'controller@distributors.pk',
        ]);
        $this->token = $this->user->createToken('test-token')->plainTextToken;

        $this->org = Organization::create([
            'name' => 'Subledger Test Org',
            'legal_name' => 'Subledger Test Org (Pvt) Ltd',
            'base_currency' => 'PKR',
            'fiscal_year_start_month' => 7,
        ]);
        $this->org->users()->attach($this->user->id, ['role' => 'owner', 'is_default' => true]);

        PakistanSmeChartTemplate::seedForOrganization($this->org);
        app(PeriodManager::class)->generateFiscalYear($this->org, 2025);

        $this->postingEngine = app(PostingEngine::class);
        $this->reconciliationService = app(SubledgerReconciliationService::class);
    }

    /**
     * P1-21: Test that duplicate reversals are strictly prevented.
     */
    public function test_duplicate_reversal_is_prevented(): void
    {
        $cash = Account::where('organization_id', $this->org->id)->where('code', '1010')->firstOrFail();
        $revenue = Account::where('organization_id', $this->org->id)->where('code', '4010')->firstOrFail();

        $entry = $this->postingEngine->createDraft($this->org, [
            'entry_date' => '2025-08-10',
            'description' => 'Original transaction',
            'lines' => [
                ['account_id' => $cash->id, 'debit' => 5000.0, 'credit' => 0.0],
                ['account_id' => $revenue->id, 'debit' => 0.0, 'credit' => 5000.0],
            ],
        ], $this->user);

        $posted = $this->postingEngine->postEntry($entry, $this->user);
        $this->assertTrue($posted->isPosted());

        // First reversal succeeds
        $reversal = $this->postingEngine->createReversal($posted, $this->user, 'Customer refund');
        $this->assertNotNull($reversal);
        $this->assertTrue($reversal->isPosted());
        $this->assertEquals($posted->id, $reversal->reversal_of_id);

        // Second reversal must throw InvalidArgumentException
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("already been reversed");
        $this->postingEngine->createReversal($posted, $this->user, 'Duplicate reversal attempt');
    }

    /**
     * P1-19 & P1-20: Test automated AR subledger reconciliation detects variances & direct manual bypasses.
     */
    public function test_ar_subledger_reconciliation_detects_matching_and_variances(): void
    {
        $arAccount = Account::where('organization_id', $this->org->id)->whereIn('code', ['1030', '1100'])->firstOrFail();
        $revenueAccount = Account::where('organization_id', $this->org->id)->where('code', '4010')->firstOrFail();

        // 1. Initial state: both zero -> reconciled
        $result = $this->reconciliationService->reconcileAccountsReceivable($this->org);
        $this->assertEquals($arAccount->code, $result['control_account_code']);
        $this->assertEquals('0.0000', $result['gl_balance']);
        $this->assertEquals('0.0000', $result['subledger_balance']);
        $this->assertEquals('0.0000', $result['variance']);
        $this->assertEquals('reconciled', $result['status']);
        $this->assertEquals(0, $result['direct_manual_postings_count']);

        // 2. Direct manual journal entry hitting control account (bypassing subledger)
        $period = \App\Domain\Accounting\Period\Models\AccountingPeriod::where('organization_id', $this->org->id)->firstOrFail();
        $manualEntry = JournalEntry::withoutGlobalScopes()->create([
            'organization_id' => $this->org->id,
            'accounting_period_id' => $period->id,
            'entry_number' => 'JE-2025-MANUAL-001',
            'entry_date' => '2025-08-15',
            'status' => 'posted',
            'source_type' => 'manual',
            'description' => 'Direct manual adjustment bypassing sales invoices',
            'currency' => 'PKR',
            'exchange_rate' => 1.000000,
            'total_amount' => 15000.0,
            'posted_at' => now(),
            'posted_by' => $this->user->id,
        ]);
        \App\Domain\Accounting\Journal\Models\JournalLine::withoutGlobalScopes()->create([
            'organization_id' => $this->org->id,
            'journal_entry_id' => $manualEntry->id,
            'account_id' => $arAccount->id,
            'line_number' => 1,
            'description' => 'Direct AR debit',
            'debit' => 15000.0,
            'credit' => 0.0,
            'currency' => 'PKR',
        ]);
        \App\Domain\Accounting\Journal\Models\JournalLine::withoutGlobalScopes()->create([
            'organization_id' => $this->org->id,
            'journal_entry_id' => $manualEntry->id,
            'account_id' => $revenueAccount->id,
            'line_number' => 2,
            'description' => 'Direct Revenue credit',
            'debit' => 0.0,
            'credit' => 15000.0,
            'currency' => 'PKR',
        ]);

        // 3. Reconcile AR: GL has 15000, Subledger has 0 -> discrepancy detected!
        $reconAfterManual = $this->reconciliationService->reconcileAccountsReceivable($this->org);
        $this->assertEquals('15000.0000', $reconAfterManual['gl_balance']);
        $this->assertEquals('0.0000', $reconAfterManual['subledger_balance']);
        $this->assertEquals('-15000.0000', $reconAfterManual['variance']);
        $this->assertEquals('discrepancy', $reconAfterManual['status']);
        $this->assertEquals(1, $reconAfterManual['direct_manual_postings_count']);
    }

    /**
     * P1-25: Test CLI command and API endpoint for subledger reconciliation.
     */
    public function test_reconciliation_cli_and_api(): void
    {
        // Test Artisan command
        $this->artisan('reconciliation:subledger', [
            'organization_id' => $this->org->id,
        ])->assertExitCode(0);

        // Test API endpoint
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson("/api/v1/organizations/{$this->org->id}/reconciliations/subledger");

        $response->assertStatus(200)
            ->assertJsonPath('data.organization_id', $this->org->id)
            ->assertJsonStructure([
                'data' => [
                    'organization_id',
                    'as_of_date',
                    'overall_status',
                    'reconciliations' => [
                        'accounts_receivable' => ['control_account_code', 'gl_balance', 'subledger_balance', 'variance', 'status'],
                        'accounts_payable' => ['control_account_code', 'gl_balance', 'subledger_balance', 'variance', 'status'],
                        'bank_accounts' => ['control_account_code', 'gl_balance', 'subledger_balance', 'variance', 'status'],
                    ],
                    'generated_at',
                ],
            ]);
    }
}
