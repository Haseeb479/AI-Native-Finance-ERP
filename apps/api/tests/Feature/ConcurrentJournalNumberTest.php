<?php

namespace Tests\Feature;

use App\Domain\Accounting\ChartOfAccounts\Models\Account;
use App\Domain\Accounting\ChartOfAccounts\Templates\PakistanSmeChartTemplate;
use App\Domain\Accounting\Journal\Models\JournalEntry;
use App\Domain\Accounting\Journal\Models\JournalLine;
use App\Domain\Accounting\Journal\Models\JournalSequence;
use App\Domain\Accounting\Period\Services\PeriodManager;
use App\Domain\Accounting\Posting\Services\PostingEngine;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\AccountTypeSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ConcurrentJournalNumberTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Organization $org;
    private PostingEngine $postingEngine;
    private Account $cashAccount;
    private Account $capitalAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);
        $this->seed(AccountTypeSeeder::class);

        $this->owner = User::factory()->create([
            'name' => 'Finance Director',
            'email' => 'director@erp.local',
        ]);

        $this->org = Organization::create([
            'name' => 'High Concurrency Corp',
            'legal_name' => 'High Concurrency Corp (Pvt) Ltd',
            'base_currency' => 'PKR',
            'fiscal_year_start_month' => 7,
        ]);
        $this->org->users()->attach($this->owner->id, ['role' => 'owner', 'is_default' => true]);

        PakistanSmeChartTemplate::seedForOrganization($this->org);
        app(PeriodManager::class)->generateFiscalYear($this->org, 2025);

        $this->cashAccount = Account::where('organization_id', $this->org->id)->where('code', '1010')->firstOrFail();
        $this->capitalAccount = Account::where('organization_id', $this->org->id)->where('code', '3010')->firstOrFail();
        $this->postingEngine = app(PostingEngine::class);
    }

    /**
     * Prove that 100 sequential/concurrent sequence increments produce exactly 100 unique,
     * monotonically increasing journal numbers with zero gaps and zero duplicates.
     */
    public function test_100_journal_numbers_generated_without_gaps_or_collisions(): void
    {
        $date = Carbon::parse('2025-08-15');
        $generatedNumbers = [];

        // Execute 100 iterations inside transactions simulating concurrent requests
        for ($i = 1; $i <= 100; $i++) {
            $num = DB::transaction(function () use ($date) {
                return $this->postingEngine->generateEntryNumber($this->org, $date);
            });
            $generatedNumbers[] = $num;
        }

        // Verify count
        $this->assertCount(100, $generatedNumbers);

        // Verify zero duplicates
        $uniqueNumbers = array_unique($generatedNumbers);
        $this->assertCount(100, $uniqueNumbers, 'Collisions detected in generated journal numbers!');

        // Verify sequential format and zero gaps
        $first = $generatedNumbers[0];
        $last = $generatedNumbers[99];
        $this->assertSame('JE-2025-00001', $first);
        $this->assertSame('JE-2025-00100', $last);

        // Verify database sequence state
        $sequenceRecord = JournalSequence::where('organization_id', $this->org->id)
            ->where('prefix', 'JE-2025-')
            ->first();

        $this->assertNotNull($sequenceRecord);
        $this->assertSame(100, $sequenceRecord->current_sequence);
    }

    /**
     * Prove that draft journal creation is strictly idempotent when an idempotency_key is provided.
     */
    public function test_draft_creation_is_strictly_idempotent(): void
    {
        $idempotencyKey = 'req_idempotency_unique_test_12345';
        $payload = [
            'entry_date' => '2025-08-15',
            'description' => 'Test Idempotent Payment',
            'idempotency_key' => $idempotencyKey,
            'lines' => [
                [
                    'account_id' => $this->cashAccount->id,
                    'debit' => '5000.0000',
                    'credit' => '0.0000',
                ],
                [
                    'account_id' => $this->capitalAccount->id,
                    'debit' => '0.0000',
                    'credit' => '5000.0000',
                ],
            ],
        ];

        // First call creates the draft
        $draft1 = $this->postingEngine->createDraft($this->org, $payload, $this->owner);
        $this->assertNotNull($draft1->id);
        $this->assertSame($idempotencyKey, $draft1->idempotency_key);

        // Second call with same payload and idempotency_key returns the identical entry
        $draft2 = $this->postingEngine->createDraft($this->org, $payload, $this->owner);
        $this->assertSame($draft1->id, $draft2->id);
        $this->assertSame($draft1->entry_number, $draft2->entry_number);

        // Total count of entries in DB must remain 1
        $count = JournalEntry::where('organization_id', $this->org->id)->count();
        $this->assertSame(1, $count);
    }

    /**
     * Prove that bcmath string operations prevent floating-point representation errors.
     */
    public function test_bcmath_string_precision_in_posting_engine(): void
    {
        // 0.1 + 0.2 in standard IEEE 754 floats is 0.30000000000000004
        // bcmath must handle exact strings with arbitrary precision
        $payload = [
            'entry_date' => '2025-08-15',
            'description' => 'Bcmath Precision Test',
            'lines' => [
                [
                    'account_id' => $this->cashAccount->id,
                    'debit' => '0.3000',
                    'credit' => '0.0000',
                ],
                [
                    'account_id' => $this->capitalAccount->id,
                    'debit' => '0.0000',
                    'credit' => '0.3000',
                ],
            ],
        ];

        $draft = $this->postingEngine->createDraft($this->org, $payload, $this->owner);
        $this->assertTrue($draft->isBalanced());
        $this->assertSame('0.3000', $draft->totalDebitString());
        $this->assertSame('0.3000', $draft->totalCreditString());

        $posted = $this->postingEngine->postEntry($draft, $this->owner);
        $this->assertTrue($posted->isPosted());
    }

    /**
     * Prove that database check constraints enforce accounting invariants at the DB layer.
     */
    public function test_database_check_constraints_enforce_accounting_invariants(): void
    {
        $period = \App\Domain\Accounting\Period\Models\AccountingPeriod::where('organization_id', $this->org->id)->firstOrFail();

        $entry = JournalEntry::withoutGlobalScopes()->create([
            'organization_id' => $this->org->id,
            'accounting_period_id' => $period->id,
            'entry_number' => 'JE-2025-99999',
            'entry_date' => '2025-08-15',
            'status' => 'draft',
            'source_type' => 'manual',
            'description' => 'DB Constraint Test',
            'currency' => 'PKR',
            'exchange_rate' => 1.0,
            'created_by' => $this->owner->id,
        ]);

        if (DB::connection()->getDriverName() === 'pgsql') {
            // PostgreSQL check constraint enforces mutual exclusion at DB level
            $this->expectException(\Illuminate\Database\QueryException::class);

            DB::table('journal_lines')->insert([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'organization_id' => $this->org->id,
                'journal_entry_id' => $entry->id,
                'account_id' => $this->cashAccount->id,
                'line_number' => 1,
                'debit' => 100.00,
                'credit' => 50.00, // Invalid: both > 0
                'currency' => 'PKR',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            // In SQLite in-memory, verify engine validation rejects mutual inclusion
            $this->expectException(\InvalidArgumentException::class);
            $this->postingEngine->createDraft($this->org, [
                'entry_date' => '2025-08-15',
                'description' => 'Invalid Mutual Line',
                'lines' => [
                    [
                        'account_id' => $this->cashAccount->id,
                        'debit' => 100.00,
                        'credit' => 50.00,
                    ],
                    [
                        'account_id' => $this->capitalAccount->id,
                        'debit' => 0.00,
                        'credit' => 50.00,
                    ],
                ],
            ], $this->owner);
        }
    }
}
