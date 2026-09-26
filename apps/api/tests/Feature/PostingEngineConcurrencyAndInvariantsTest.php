<?php

namespace Tests\Feature;

use App\Domain\Accounting\ChartOfAccounts\Models\Account;
use App\Domain\Accounting\Period\Models\FiscalYear;
use App\Domain\Accounting\Journal\Models\JournalEntry;
use App\Domain\Accounting\Period\Models\AccountingPeriod;
use App\Domain\Accounting\Posting\Exceptions\ClosedPeriodException;
use App\Domain\Accounting\Posting\Exceptions\UnbalancedJournalException;
use App\Domain\Accounting\Posting\Services\PostingEngine;
use App\Domain\Organization\Models\Entity;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

use App\Domain\Accounting\ChartOfAccounts\Templates\PakistanSmeChartTemplate;
use App\Domain\Accounting\Period\Services\PeriodManager;
use Database\Seeders\AccountTypeSeeder;

class PostingEngineConcurrencyAndInvariantsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;
    private Organization $orgB;
    private User $userA;
    private AccountingPeriod $periodA;
    private Account $cashA;
    private Account $revenueA;
    private Account $inactiveA;
    private PostingEngine $postingEngine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->postingEngine = app(PostingEngine::class);

        // Org A
        $this->orgA = Organization::create([
            'name' => 'Org Alpha',
            'slug' => 'org-alpha',
            'legal_name' => 'Org Alpha Ltd',
            'country_code' => 'PK',
            'base_currency' => 'PKR',
        ]);

        // Org B (different tenant)
        $this->orgB = Organization::create([
            'name' => 'Org Beta',
            'slug' => 'org-beta',
            'legal_name' => 'Org Beta Ltd',
            'country_code' => 'PK',
            'base_currency' => 'PKR',
        ]);

        $this->userA = User::factory()->create();
        $this->orgA->users()->attach($this->userA, ['role' => 'admin', 'is_default' => true]);

        // Seed Account Types, Chart of Accounts & Fiscal Year
        (new AccountTypeSeeder())->run();
        PakistanSmeChartTemplate::seedForOrganization($this->orgA);
        PakistanSmeChartTemplate::seedForOrganization($this->orgB);
        app(PeriodManager::class)->generateFiscalYear($this->orgA, 2025);
        app(PeriodManager::class)->generateFiscalYear($this->orgB, 2025);

        $this->periodA = AccountingPeriod::where('organization_id', $this->orgA->id)
            ->where('period_number', 1)
            ->firstOrFail();

        $this->cashA = Account::where('organization_id', $this->orgA->id)->where('code', '1010')->firstOrFail();
        $this->revenueA = Account::where('organization_id', $this->orgA->id)->where('code', '4010')->firstOrFail();

        $this->inactiveA = Account::where('organization_id', $this->orgA->id)->where('code', '6020')->firstOrFail();
        $this->inactiveA->update(['is_active' => false]);
    }

    /**
     * P0-05: Test that postEntry is idempotent on already-posted entries.
     */
    public function test_post_entry_is_idempotent_when_called_multiple_times(): void
    {
        $draft = $this->postingEngine->createDraft($this->orgA, [
            'entry_date' => '2025-08-15',
            'description' => 'Test cash sale',
            'lines' => [
                ['account_id' => $this->cashA->id, 'debit' => 1000.0, 'credit' => 0.0],
                ['account_id' => $this->revenueA->id, 'debit' => 0.0, 'credit' => 1000.0],
            ],
        ], $this->userA);

        $postedFirst = $this->postingEngine->postEntry($draft, $this->userA);
        $this->assertTrue($postedFirst->isPosted());
        $this->assertEquals(1000.0, (float) $postedFirst->total_amount);

        // Second call should be idempotent and return the posted entry safely
        $postedSecond = $this->postingEngine->postEntry($draft, $this->userA);
        $this->assertTrue($postedSecond->isPosted());
        $this->assertEquals($postedFirst->id, $postedSecond->id);
    }

    /**
     * P0-06: Concurrency test - sequential/repeated draft creation produces unique sequential numbers.
     */
    public function test_sequential_entry_numbering_is_unique_and_monotonic(): void
    {
        $numbers = [];
        for ($i = 0; $i < 20; $i++) {
            $draft = $this->postingEngine->createDraft($this->orgA, [
                'entry_date' => '2025-08-15',
                'description' => "Batch creation {$i}",
                'lines' => [
                    ['account_id' => $this->cashA->id, 'debit' => 10.0, 'credit' => 0.0],
                    ['account_id' => $this->revenueA->id, 'debit' => 0.0, 'credit' => 10.0],
                ],
            ], $this->userA);

            $numbers[] = $draft->entry_number;
        }

        $this->assertCount(20, $numbers);
        $this->assertCount(20, array_unique($numbers), "All entry numbers must be strictly unique.");
        $this->assertEquals('JE-2025-00001', $numbers[0]);
        $this->assertEquals('JE-2025-00020', $numbers[19]);
    }

    /**
     * P0-07: Invariant - journal entry must have at least 2 lines.
     */
    public function test_cannot_create_journal_with_less_than_two_lines(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('at least 2 lines');

        $this->postingEngine->createDraft($this->orgA, [
            'entry_date' => '2025-08-15',
            'description' => 'Single line violation',
            'lines' => [
                ['account_id' => $this->cashA->id, 'debit' => 100.0, 'credit' => 0.0],
            ],
        ], $this->userA);
    }

    /**
     * P0-07: Invariant - negative debit/credit is rejected.
     */
    public function test_cannot_create_line_with_negative_amount(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('strictly non-negative');

        $this->postingEngine->createDraft($this->orgA, [
            'entry_date' => '2025-08-15',
            'description' => 'Negative debit violation',
            'lines' => [
                ['account_id' => $this->cashA->id, 'debit' => -50.0, 'credit' => 0.0],
                ['account_id' => $this->revenueA->id, 'debit' => 0.0, 'credit' => 50.0],
            ],
        ], $this->userA);
    }

    /**
     * P0-07: Invariant - line cannot have both debit and credit.
     */
    public function test_cannot_create_line_with_both_debit_and_credit(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('mutually exclusive');

        $this->postingEngine->createDraft($this->orgA, [
            'entry_date' => '2025-08-15',
            'description' => 'Both debit and credit violation',
            'lines' => [
                ['account_id' => $this->cashA->id, 'debit' => 100.0, 'credit' => 50.0],
                ['account_id' => $this->revenueA->id, 'debit' => 0.0, 'credit' => 50.0],
            ],
        ], $this->userA);
    }

    /**
     * P0-07: Invariant - line cannot have both amounts zero.
     */
    public function test_cannot_create_line_with_zero_amount(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('greater than zero');

        $this->postingEngine->createDraft($this->orgA, [
            'entry_date' => '2025-08-15',
            'description' => 'Zero amount violation',
            'lines' => [
                ['account_id' => $this->cashA->id, 'debit' => 0.0, 'credit' => 0.0],
                ['account_id' => $this->revenueA->id, 'debit' => 0.0, 'credit' => 50.0],
            ],
        ], $this->userA);
    }

    /**
     * P0-07: Invariant - posting to inactive account is rejected.
     */
    public function test_cannot_create_draft_with_inactive_account(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('inactive');

        $this->postingEngine->createDraft($this->orgA, [
            'entry_date' => '2025-08-15',
            'description' => 'Inactive account violation',
            'lines' => [
                ['account_id' => $this->inactiveA->id, 'debit' => 100.0, 'credit' => 0.0],
                ['account_id' => $this->revenueA->id, 'debit' => 0.0, 'credit' => 100.0],
            ],
        ], $this->userA);
    }

    /**
     * P0-07: Period must cover entry date.
     */
    public function test_cannot_create_draft_when_period_does_not_cover_date(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does not cover entry date');

        $this->postingEngine->createDraft($this->orgA, [
            'entry_date' => '2025-06-15', // June is outside January 2025 period
            'accounting_period_id' => $this->periodA->id,
            'description' => 'Out of period test',
            'lines' => [
                ['account_id' => $this->cashA->id, 'debit' => 100.0, 'credit' => 0.0],
                ['account_id' => $this->revenueA->id, 'debit' => 0.0, 'credit' => 100.0],
            ],
        ], $this->userA);
    }

    /**
     * P0-08: Cross-tenant account injection is rejected.
     */
    public function test_cannot_create_draft_with_foreign_tenant_account(): void
    {
        $accountB = Account::where('organization_id', $this->orgB->id)->where('code', '1010')->firstOrFail();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not found or does not belong');

        $this->postingEngine->createDraft($this->orgA, [
            'entry_date' => '2025-08-15',
            'description' => 'Cross-tenant account attack',
            'lines' => [
                ['account_id' => $accountB->id, 'debit' => 100.0, 'credit' => 0.0],
                ['account_id' => $this->revenueA->id, 'debit' => 0.0, 'credit' => 100.0],
            ],
        ], $this->userA);
    }

    /**
     * P0-08: Cross-tenant entity injection is rejected.
     */
    public function test_cannot_create_draft_with_foreign_tenant_entity(): void
    {
        $entityB = Entity::create([
            'organization_id' => $this->orgB->id,
            'name' => 'Entity Beta',
            'code' => 'ENT-B',
            'currency' => 'PKR',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Entity does not belong to organization');

        $this->postingEngine->createDraft($this->orgA, [
            'entity_id' => $entityB->id,
            'entry_date' => '2025-08-15',
            'description' => 'Cross-tenant entity attack',
            'lines' => [
                ['account_id' => $this->cashA->id, 'debit' => 100.0, 'credit' => 0.0],
                ['account_id' => $this->revenueA->id, 'debit' => 0.0, 'credit' => 100.0],
            ],
        ], $this->userA);
    }

    /**
     * P0-08: Cross-tenant period injection is rejected.
     */
    public function test_cannot_create_draft_with_foreign_tenant_period(): void
    {
        $periodB = AccountingPeriod::where('organization_id', $this->orgB->id)->firstOrFail();

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        $this->postingEngine->createDraft($this->orgA, [
            'accounting_period_id' => $periodB->id,
            'entry_date' => '2025-08-15',
            'description' => 'Cross-tenant period attack',
            'lines' => [
                ['account_id' => $this->cashA->id, 'debit' => 100.0, 'credit' => 0.0],
                ['account_id' => $this->revenueA->id, 'debit' => 0.0, 'credit' => 100.0],
            ],
        ], $this->userA);
    }

    /**
     * Test duplicate reversal rejection.
     */
    public function test_cannot_reverse_entry_more_than_once(): void
    {
        $draft = $this->postingEngine->createDraft($this->orgA, [
            'entry_date' => '2025-08-15',
            'description' => 'Entry for reversal',
            'lines' => [
                ['account_id' => $this->cashA->id, 'debit' => 500.0, 'credit' => 0.0],
                ['account_id' => $this->revenueA->id, 'debit' => 0.0, 'credit' => 500.0],
            ],
        ], $this->userA);

        $posted = $this->postingEngine->postEntry($draft, $this->userA);

        $reversal = $this->postingEngine->createReversal($posted, $this->userA, 'First reversal');
        $this->assertTrue($reversal->isPosted());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('has already been reversed');

        $this->postingEngine->createReversal($posted, $this->userA, 'Second reversal attempt');
    }
}
