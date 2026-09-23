<?php

namespace Tests\Feature;

use App\Domain\Accounting\ChartOfAccounts\Models\Account;
use App\Domain\Accounting\ChartOfAccounts\Templates\PakistanSmeChartTemplate;
use App\Domain\Accounting\Period\Models\AccountingPeriod;
use App\Domain\Accounting\Period\Services\PeriodManager;
use App\Domain\Consolidation\Models\ExchangeRate;
use App\Domain\Consolidation\Models\IntercompanyTransaction;
use App\Domain\Consolidation\Services\CurrencyService;
use App\Domain\Organization\Models\Entity;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Database\Seeders\AccountTypeSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MultiEntityConsolidationTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Organization $org;
    private string $ownerToken;
    private Entity $parentEntity;
    private Entity $subEntity;
    private AccountingPeriod $period;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);
        $this->seed(AccountTypeSeeder::class);

        $this->owner = User::factory()->create([
            'name' => 'Group CFO Tariq',
            'email' => 'groupcfo@holding.pk',
        ]);
        $this->ownerToken = $this->owner->createToken('owner')->plainTextToken;

        $this->org = Organization::create([
            'name' => 'Indus Holdings Group',
            'legal_name' => 'Indus Holdings Group (Pvt) Ltd',
            'base_currency' => 'PKR',
            'fiscal_year_start_month' => 7,
        ]);
        $this->org->users()->attach($this->owner->id, ['role' => 'owner', 'is_default' => true]);

        PakistanSmeChartTemplate::seedForOrganization($this->org);
        app(PeriodManager::class)->generateFiscalYear($this->org, 2025);

        $this->period = AccountingPeriod::where('organization_id', $this->org->id)->orderBy('start_date')->first();

        // Create Parent and Subsidiary entities
        $this->parentEntity = Entity::create([
            'organization_id' => $this->org->id,
            'name' => 'Indus Holding Corp (Parent)',
            'code' => 'HOLDING',
            'currency' => 'PKR',
            'is_primary' => true,
            'status' => 'active',
        ]);

        $this->subEntity = Entity::create([
            'organization_id' => $this->org->id,
            'name' => 'Indus Logistics Services (Sub)',
            'code' => 'LOGISTICS',
            'currency' => 'PKR',
            'is_primary' => false,
            'status' => 'active',
        ]);
    }

    public function test_can_manage_multiple_legal_entities_and_exchange_rates(): void
    {
        // 1. Create a foreign subsidiary (Dubai Middle East)
        $entityResponse = $this->withHeader('Authorization', "Bearer {$this->ownerToken}")
            ->postJson("/api/v1/organizations/{$this->org->id}/entities", [
                'name' => 'Indus Gulf Tech FZE',
                'code' => 'GULF',
                'currency' => 'AED',
            ]);

        $entityResponse->assertStatus(201)
            ->assertJsonPath('data.code', 'GULF')
            ->assertJsonPath('data.currency', 'AED');

        // 2. Set foreign exchange rate AED to PKR = 76.50
        $rateResponse = $this->withHeader('Authorization', "Bearer {$this->ownerToken}")
            ->postJson("/api/v1/organizations/{$this->org->id}/exchange-rates", [
                'from_currency' => 'AED',
                'to_currency' => 'PKR',
                'rate' => 76.500000,
                'effective_date' => '2025-07-01',
                'source' => 'state_bank_pakistan',
            ]);

        $rateResponse->assertStatus(201)
            ->assertJsonPath('data.from_currency', 'AED')
            ->assertJsonPath('data.to_currency', 'PKR')
            ->assertJsonPath('data.rate', '76.500000');

        // 3. Test currency conversion service
        $currencyService = app(CurrencyService::class);
        $conversion = $currencyService->convert($this->org, 1000, 'AED', 'PKR', '2025-07-15');

        $this->assertEquals(76500.0, (float) $conversion['converted_amount']);
        $this->assertEquals(76.50, (float) $conversion['exchange_rate']);
    }

    public function test_can_run_month_end_unrealized_fx_currency_revaluation(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->ownerToken}")
            ->postJson("/api/v1/organizations/{$this->org->id}/currency-revaluation", [
                'accounting_period_id' => $this->period->id,
                'spot_rate_usd' => 282.50, // Booked at 270, Spot at 282.50 = +12.5/USD Gain on $10k exposure = PKR 125,000 gain
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.revaluation_posted', true)
            ->assertJsonPath('data.variance_amount', 125000);

        $journalId = $response->json('data.journal_entry_id');
        $this->assertNotNull($journalId);

        // Verify balanced journal lines
        $this->assertDatabaseHas('journal_lines', [
            'organization_id' => $this->org->id,
            'journal_entry_id' => $journalId,
            'debit' => 125000,
        ]);

        $this->assertDatabaseHas('journal_lines', [
            'organization_id' => $this->org->id,
            'journal_entry_id' => $journalId,
            'credit' => 125000,
        ]);

        $this->assertDatabaseHas('audit_events', [
            'organization_id' => $this->org->id,
            'event' => 'currency:revalued',
        ]);
    }

    public function test_intercompany_transaction_lifecycle_and_reciprocal_posting(): void
    {
        // 1. Create Intercompany Transaction: Shared Corporate Services PKR 150,000 from Parent to Sub
        $createResponse = $this->withHeader('Authorization', "Bearer {$this->ownerToken}")
            ->postJson("/api/v1/organizations/{$this->org->id}/intercompany-transactions", [
                'from_entity_id' => $this->parentEntity->id,
                'to_entity_id' => $this->subEntity->id,
                'transaction_date' => '2025-07-20',
                'currency' => 'PKR',
                'amount' => 150000,
                'description' => 'Shared Cloud ERP Infrastructure and Accounting Overhead',
            ]);

        $createResponse->assertStatus(201)
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.amount', '150000.0000')
            ->assertJsonPath('data.base_amount', '150000.0000');

        $txId = $createResponse->json('data.id');

        // 2. Post Intercompany Transaction
        $postResponse = $this->withHeader('Authorization', "Bearer {$this->ownerToken}")
            ->postJson("/api/v1/organizations/{$this->org->id}/intercompany-transactions/{$txId}/post");

        $postResponse->assertStatus(200)
            ->assertJsonPath('data.status', 'posted');

        $fromJournalId = $postResponse->json('data.from_journal_entry_id');
        $toJournalId = $postResponse->json('data.to_journal_entry_id');

        $this->assertNotNull($fromJournalId);
        $this->assertNotNull($toJournalId);

        // Verify Entity A (Parent): Intercompany AR Debited
        $this->assertDatabaseHas('journal_lines', [
            'organization_id' => $this->org->id,
            'journal_entry_id' => $fromJournalId,
            'debit' => 150000,
        ]);

        // Verify Entity B (Sub): Intercompany AP Credited
        $this->assertDatabaseHas('journal_lines', [
            'organization_id' => $this->org->id,
            'journal_entry_id' => $toJournalId,
            'credit' => 150000,
        ]);
    }

    public function test_intercompany_elimination_routine_and_balanced_elimination_journal(): void
    {
        // 1. Create and post an intercompany transaction
        $tx = app(\App\Domain\Consolidation\Services\ConsolidationService::class)->createIntercompanyTransaction($this->org, [
            'from_entity_id' => $this->parentEntity->id,
            'to_entity_id' => $this->subEntity->id,
            'transaction_date' => '2025-07-25',
            'currency' => 'PKR',
            'amount' => 80000,
            'description' => 'Legal and Compliance Fee Allocation',
        ], $this->owner);

        app(\App\Domain\Consolidation\Services\ConsolidationService::class)->postIntercompanyTransaction($tx, $this->owner);
        $this->assertEquals('posted', $tx->fresh()->status);

        // 2. Run Intercompany Elimination Routine
        $elimResponse = $this->withHeader('Authorization', "Bearer {$this->ownerToken}")
            ->postJson("/api/v1/organizations/{$this->org->id}/consolidation/eliminate", [
                'accounting_period_id' => $this->period->id,
            ]);

        $elimResponse->assertStatus(200)
            ->assertJsonPath('data.eliminated', true)
            ->assertJsonPath('data.total_amount', 80000)
            ->assertJsonPath('data.transactions_count', 1);

        $eliminationJournalId = $elimResponse->json('data.elimination_journal_id');
        $this->assertNotNull($eliminationJournalId);

        // Verify balanced reciprocal elimination entry: Debit AP (80,000), Credit AR (80,000)
        $this->assertDatabaseHas('journal_lines', [
            'organization_id' => $this->org->id,
            'journal_entry_id' => $eliminationJournalId,
            'debit' => 80000,
        ]);

        $this->assertDatabaseHas('journal_lines', [
            'organization_id' => $this->org->id,
            'journal_entry_id' => $eliminationJournalId,
            'credit' => 80000,
        ]);

        // Verify transaction updated to 'eliminated'
        $this->assertEquals('eliminated', $tx->fresh()->status);

        $this->assertDatabaseHas('audit_events', [
            'organization_id' => $this->org->id,
            'event' => 'intercompany:eliminated',
        ]);
    }

    public function test_consolidated_financial_report_with_entity_breakdown(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->ownerToken}")
            ->getJson("/api/v1/organizations/{$this->org->id}/consolidation/reports/trial-balance?accounting_period_id={$this->period->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.report_type', 'trial-balance')
            ->assertJsonPath('data.is_balanced', true)
            ->assertJsonStructure([
                'data' => [
                    'organization_id',
                    'report_type',
                    'period',
                    'entities',
                    'total_debit',
                    'total_credit',
                    'is_balanced',
                    'items' => [
                        '*' => ['account_code', 'account_name', 'classification', 'entity_breakdown', 'eliminations', 'consolidated_balance'],
                    ],
                ],
            ]);
    }
}
