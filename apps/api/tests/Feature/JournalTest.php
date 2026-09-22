<?php

namespace Tests\Feature;

use App\Domain\Accounting\ChartOfAccounts\Models\Account;
use App\Domain\Accounting\ChartOfAccounts\Templates\PakistanSmeChartTemplate;
use App\Domain\Accounting\Journal\Models\JournalEntry;
use App\Domain\Accounting\Period\Services\PeriodManager;
use App\Domain\Accounting\Posting\Services\PostingEngine;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Database\Seeders\AccountTypeSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JournalTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Organization $org;
    private string $token;
    private Account $cashAccount;
    private Account $capitalAccount;
    private Account $expenseAccount;
    private Account $bankAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);
        $this->seed(AccountTypeSeeder::class);

        $this->owner = User::factory()->create([
            'name' => 'Asad Siddiqui',
            'email' => 'asad@fintech.pk',
        ]);

        $this->token = $this->owner->createToken('test-token')->plainTextToken;

        $this->org = Organization::create([
            'name' => 'FinTech Ventures PK',
            'legal_name' => 'FinTech Ventures Pakistan (Pvt) Ltd',
            'base_currency' => 'PKR',
            'fiscal_year_start_month' => 7,
        ]);
        $this->org->users()->attach($this->owner->id, ['role' => 'owner', 'is_default' => true]);

        // Seed Chart of Accounts and Fiscal Year for 2025
        PakistanSmeChartTemplate::seedForOrganization($this->org);
        app(PeriodManager::class)->generateFiscalYear($this->org, 2025);

        $this->cashAccount = Account::where('organization_id', $this->org->id)->where('code', '1010')->firstOrFail();
        $this->bankAccount = Account::where('organization_id', $this->org->id)->where('code', '1020')->firstOrFail();
        $this->capitalAccount = Account::where('organization_id', $this->org->id)->where('code', '3010')->firstOrFail();
        $this->expenseAccount = Account::where('organization_id', $this->org->id)->where('code', '6020')->firstOrFail(); // Rent
    }

    public function test_can_create_draft_journal_entry(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson("/api/v1/organizations/{$this->org->id}/journals", [
                'entry_date' => '2025-08-15',
                'description' => 'Initial capital deposit by founder',
                'lines' => [
                    [
                        'account_id' => $this->cashAccount->id,
                        'description' => 'Cash injected into business',
                        'debit' => 100000.00,
                        'credit' => 0.00,
                    ],
                    [
                        'account_id' => $this->capitalAccount->id,
                        'description' => 'Owners capital credit',
                        'debit' => 0.00,
                        'credit' => 100000.00,
                    ],
                ],
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.entry_number', 'JE-2025-00001');

        $this->assertDatabaseHas('journal_entries', [
            'organization_id' => $this->org->id,
            'entry_number' => 'JE-2025-00001',
            'status' => 'draft',
        ]);
    }

    public function test_can_post_balanced_journal_entry(): void
    {
        $postingEngine = app(PostingEngine::class);

        $draft = $postingEngine->createDraft($this->org, [
            'entry_date' => '2025-08-15',
            'description' => 'Capital injection',
            'lines' => [
                ['account_id' => $this->cashAccount->id, 'debit' => 50000.00, 'credit' => 0.00],
                ['account_id' => $this->capitalAccount->id, 'debit' => 0.00, 'credit' => 50000.00],
            ],
        ], $this->owner);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson("/api/v1/organizations/{$this->org->id}/journals/{$draft->id}/post");

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'posted')
            ->assertJsonPath('data.posted_by', $this->owner->id);

        $draft->refresh();
        $this->assertTrue($draft->isPosted());
        $this->assertEquals(50000.00, (float) $draft->total_amount);
        $this->assertNotNull($draft->posted_at);
    }

    public function test_cannot_post_unbalanced_journal_entry(): void
    {
        $postingEngine = app(PostingEngine::class);

        // Unbalanced: Debit 50,000 vs Credit 40,000
        $draft = $postingEngine->createDraft($this->org, [
            'entry_date' => '2025-08-15',
            'description' => 'Unbalanced entry test',
            'lines' => [
                ['account_id' => $this->cashAccount->id, 'debit' => 50000.00, 'credit' => 0.00],
                ['account_id' => $this->capitalAccount->id, 'debit' => 0.00, 'credit' => 40000.00],
            ],
        ], $this->owner);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson("/api/v1/organizations/{$this->org->id}/journals/{$draft->id}/post");

        $response->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'POSTING_FAILED');

        $draft->refresh();
        $this->assertTrue($draft->isDraft());
    }

    public function test_cannot_post_to_closed_period(): void
    {
        $periodManager = app(PeriodManager::class);
        $postingEngine = app(PostingEngine::class);

        $draft = $postingEngine->createDraft($this->org, [
            'entry_date' => '2025-07-20',
            'description' => 'Rent payment July',
            'lines' => [
                ['account_id' => $this->expenseAccount->id, 'debit' => 30000.00, 'credit' => 0.00],
                ['account_id' => $this->bankAccount->id, 'debit' => 0.00, 'credit' => 30000.00],
            ],
        ], $this->owner);

        // Close the period
        $periodManager->closePeriod($draft->period, $this->owner);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson("/api/v1/organizations/{$this->org->id}/journals/{$draft->id}/post");

        $response->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'POSTING_FAILED');
    }

    public function test_posted_journal_entry_is_immutable(): void
    {
        $postingEngine = app(PostingEngine::class);

        $draft = $postingEngine->createDraft($this->org, [
            'entry_date' => '2025-09-10',
            'description' => 'Electricity bill',
            'lines' => [
                ['account_id' => $this->expenseAccount->id, 'debit' => 15000.00, 'credit' => 0.00],
                ['account_id' => $this->bankAccount->id, 'debit' => 0.00, 'credit' => 15000.00],
            ],
        ], $this->owner);

        $postingEngine->postEntry($draft, $this->owner);

        // Attempt to modify description
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->putJson("/api/v1/organizations/{$this->org->id}/journals/{$draft->id}", [
                'description' => 'Hacked description modification',
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'JOURNAL_IMMUTABLE');
    }

    public function test_reversal_creates_exact_opposite_balanced_journal_entry(): void
    {
        $postingEngine = app(PostingEngine::class);

        $original = $postingEngine->createDraft($this->org, [
            'entry_date' => '2025-09-10',
            'description' => 'Erroneous rent payment',
            'lines' => [
                ['account_id' => $this->expenseAccount->id, 'description' => 'Rent debit', 'debit' => 45000.00, 'credit' => 0.00],
                ['account_id' => $this->bankAccount->id, 'description' => 'Bank credit', 'debit' => 0.00, 'credit' => 45000.00],
            ],
        ], $this->owner);

        $posted = $postingEngine->postEntry($original, $this->owner);

        // Perform reversal
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson("/api/v1/organizations/{$this->org->id}/journals/{$posted->id}/reverse", [
                'reason' => 'Duplicate payment entered in error',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.status', 'posted')
            ->assertJsonPath('data.reversal_of_id', $posted->id);

        $reversalId = $response->json('data.id');
        $reversal = JournalEntry::with('lines')->findOrFail($reversalId);

        // Verify mathematical balance: Debit == Credit == 45,000
        $this->assertTrue($reversal->isBalanced());
        $this->assertEquals(45000.00, $reversal->totalDebit());
        $this->assertEquals(45000.00, $reversal->totalCredit());

        // Verify lines swapped: Expense is now credit, Bank is now debit
        $expenseLine = $reversal->lines->firstWhere('account_id', $this->expenseAccount->id);
        $bankLine = $reversal->lines->firstWhere('account_id', $this->bankAccount->id);

        $this->assertEquals(0.00, (float) $expenseLine->debit);
        $this->assertEquals(45000.00, (float) $expenseLine->credit);

        $this->assertEquals(45000.00, (float) $bankLine->debit);
        $this->assertEquals(0.00, (float) $bankLine->credit);
    }

    public function test_can_create_and_auto_post_journal_entry(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson("/api/v1/organizations/{$this->org->id}/journals", [
                'entry_date' => '2025-08-20',
                'description' => 'Direct deposit auto-post',
                'auto_post' => true,
                'lines' => [
                    ['account_id' => $this->bankAccount->id, 'debit' => 25000.00, 'credit' => 0.00],
                    ['account_id' => $this->capitalAccount->id, 'debit' => 0.00, 'credit' => 25000.00],
                ],
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.status', 'posted')
            ->assertJsonPath('data.posted_by', $this->owner->id);
    }

    public function test_staff_without_posting_permission_cannot_post_journal(): void
    {
        $staff = User::factory()->create(['email' => 'staff@fintech.pk']);
        $staffToken = $staff->createToken('staff-token')->plainTextToken;
        $this->org->users()->attach($staff->id, ['role' => 'staff']);

        $postingEngine = app(PostingEngine::class);

        $draft = $postingEngine->createDraft($this->org, [
            'entry_date' => '2025-08-15',
            'description' => 'Staff created draft',
            'lines' => [
                ['account_id' => $this->cashAccount->id, 'debit' => 10000.00, 'credit' => 0.00],
                ['account_id' => $this->capitalAccount->id, 'debit' => 0.00, 'credit' => 10000.00],
            ],
        ], $this->owner);

        // Staff tries to post
        $response = $this->withHeader('Authorization', "Bearer {$staffToken}")
            ->postJson("/api/v1/organizations/{$this->org->id}/journals/{$draft->id}/post");

        $response->assertStatus(403)
            ->assertJsonPath('errors.0.code', 'FORBIDDEN');
    }

    public function test_tenant_isolation_prevents_viewing_journals_of_another_tenant(): void
    {
        $intruder = User::factory()->create(['email' => 'intruder@other.pk']);
        $intruderToken = $intruder->createToken('intruder-token')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$intruderToken}")
            ->getJson("/api/v1/organizations/{$this->org->id}/journals");

        $response->assertStatus(404);
    }
}
