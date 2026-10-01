<?php

namespace Tests\Feature;

use App\Domain\Accounting\ChartOfAccounts\Models\Account;
use App\Domain\Accounting\ChartOfAccounts\Templates\PakistanSmeChartTemplate;
use App\Domain\Accounting\Period\Models\AccountingPeriod;
use App\Domain\Accounting\Period\Services\PeriodManager;
use App\Domain\Accounting\Posting\Services\PostingEngine;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Database\Seeders\AccountTypeSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CashFlowStatementTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Organization $org;
    private string $token;
    private PostingEngine $postingEngine;
    private AccountingPeriod $period;

    private Account $cashAccount;
    private Account $revenueAccount;
    private Account $ppeAccount;
    private Account $equityAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);
        $this->seed(AccountTypeSeeder::class);

        $this->owner = User::factory()->create([
            'name'  => 'CFO User',
            'email' => 'cfo@enterprise.test',
        ]);
        $this->token = $this->owner->createToken('test')->plainTextToken;

        $this->org = Organization::create([
            'name'                    => 'Acme Corp',
            'legal_name'              => 'Acme Global Corp',
            'base_currency'           => 'PKR',
            'fiscal_year_start_month' => 7,
        ]);
        $this->org->users()->attach($this->owner->id, ['role' => 'owner', 'is_default' => true]);

        PakistanSmeChartTemplate::seedForOrganization($this->org);
        app(PeriodManager::class)->generateFiscalYear($this->org, 2025);

        $this->period = AccountingPeriod::where('organization_id', $this->org->id)
            ->where('status', 'open')
            ->firstOrFail();

        $this->postingEngine = app(PostingEngine::class);

        // Find or create needed accounts
        $this->cashAccount = Account::where('organization_id', $this->org->id)
            ->where('code', 'like', '1010%')->first()
            ?? Account::where('organization_id', $this->org->id)->where('classification', 'asset')->first();

        $this->revenueAccount = Account::where('organization_id', $this->org->id)
            ->where('classification', 'revenue')->first();

        $this->equityAccount = Account::where('organization_id', $this->org->id)
            ->where('classification', 'equity')->first();

        $this->ppeAccount = Account::where('organization_id', $this->org->id)
            ->where('code', 'like', '15%')->first();
        if (!$this->ppeAccount) {
            $this->ppeAccount = Account::create([
                'organization_id' => $this->org->id,
                'code' => '1510',
                'name' => 'Plant & Equipment',
                'classification' => 'asset',
                'normal_balance' => 'debit',
                'is_active' => true,
            ]);
        }
    }

    public function test_cash_flow_statement_calculates_operating_investing_and_financing(): void
    {
        // 1. Post operating revenue (Cash Debit 50,000 / Revenue Credit 50,000)
        $draft1 = $this->postingEngine->createDraft($this->org, [
            'entry_date' => '2025-07-15',
            'accounting_period_id' => $this->period->id,
            'description' => 'Consulting Cash Revenue',
            'lines' => [
                ['account_id' => $this->cashAccount->id, 'debit' => 50000, 'credit' => 0],
                ['account_id' => $this->revenueAccount->id, 'debit' => 0, 'credit' => 50000],
            ],
        ], $this->owner);
        $this->postingEngine->postEntry($draft1, $this->owner);

        // 2. Post capex (PPE Debit 20,000 / Cash Credit 20,000)
        $draft2 = $this->postingEngine->createDraft($this->org, [
            'entry_date' => '2025-07-20',
            'accounting_period_id' => $this->period->id,
            'description' => 'Equipment purchase',
            'lines' => [
                ['account_id' => $this->ppeAccount->id, 'debit' => 20000, 'credit' => 0],
                ['account_id' => $this->cashAccount->id, 'debit' => 0, 'credit' => 20000],
            ],
        ], $this->owner);
        $this->postingEngine->postEntry($draft2, $this->owner);

        // Request Cash Flow report
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson("/api/v1/organizations/{$this->org->id}/reports/cash-flow?from=2025-07-01&to=2025-09-30");

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'from_date',
                    'to_date',
                    'operating_activities' => [
                        'net_income',
                        'depreciation_amortization',
                        'working_capital_changes',
                        'net_cash_from_operations',
                    ],
                    'investing_activities' => [
                        'capital_expenditures',
                        'net_cash_from_investing',
                    ],
                    'financing_activities' => [
                        'debt_financing',
                        'equity_financing',
                        'net_cash_from_financing',
                    ],
                    'summary' => [
                        'net_cash_increase_decrease',
                        'cash_at_beginning',
                        'cash_at_end',
                    ],
                ],
                'meta' => [
                    'report',
                    'organization_id',
                    'from_date',
                    'to_date',
                ],
            ]);

        $data = $response->json('data');
        $this->assertEquals(50000.0, (float) $data['operating_activities']['net_income']);
        $this->assertEquals(-20000.0, (float) $data['investing_activities']['capital_expenditures']);
        $this->assertEquals(30000.0, (float) $data['summary']['net_cash_increase_decrease']);
    }

    public function test_cash_flow_validates_date_parameters(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson("/api/v1/organizations/{$this->org->id}/reports/cash-flow?from=2025-09-30&to=2025-07-01");

        $response->assertStatus(422);
    }
}
