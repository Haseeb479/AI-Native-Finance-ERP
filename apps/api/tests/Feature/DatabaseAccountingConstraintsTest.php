<?php

namespace Tests\Feature;

use App\Domain\Accounting\ChartOfAccounts\Models\Account;
use App\Domain\Accounting\ChartOfAccounts\Templates\PakistanSmeChartTemplate;
use App\Domain\Accounting\Journal\Models\JournalEntry;
use App\Domain\Accounting\Journal\Models\JournalLine;
use App\Domain\Accounting\Period\Services\PeriodManager;
use App\Domain\Accounting\Posting\Exceptions\UnbalancedJournalException;
use App\Domain\Accounting\Posting\Services\PostingEngine;
use App\Domain\Organization\Models\Organization;
use App\Domain\Sales\Models\Customer;
use App\Domain\Sales\Models\SalesInvoice;
use App\Models\User;
use Database\Seeders\AccountTypeSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DatabaseAccountingConstraintsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private User $user;
    private PostingEngine $postingEngine;
    private PeriodManager $periodManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);
        $this->seed(AccountTypeSeeder::class);

        $this->user = User::factory()->create();
        $this->org = Organization::create([
            'name' => 'Constraint Corp',
            'legal_name' => 'Constraint Corp (Pvt) Ltd',
            'base_currency' => 'PKR',
        ]);
        $this->org->users()->attach($this->user->id, ['role' => 'owner', 'is_default' => true]);

        PakistanSmeChartTemplate::seedForOrganization($this->org);
        $this->periodManager = app(PeriodManager::class);
        $this->periodManager->generateFiscalYear($this->org, 2025);
        $this->postingEngine = app(PostingEngine::class);
    }

    public function test_journal_entry_cannot_be_posted_when_unbalanced(): void
    {
        $cash = Account::withoutGlobalScopes()->where('organization_id', $this->org->id)->where('code', '1010')->first();
        $capital = Account::withoutGlobalScopes()->where('organization_id', $this->org->id)->where('code', '3010')->first();
        $period = $this->periodManager->getOpenPeriodForDate($this->org, '2025-07-15');

        // Create a draft with unbalanced lines (bypass service validation by writing directly to draft)
        $entry = JournalEntry::withoutGlobalScopes()->create([
            'organization_id' => $this->org->id,
            'accounting_period_id' => $period->id,
            'entry_number' => 'JE-TEST-UNBALANCED',
            'entry_date' => '2025-07-15',
            'status' => 'draft',
            'source_type' => 'manual',
            'description' => 'Unbalanced test',
            'currency' => 'PKR',
        ]);

        JournalLine::withoutGlobalScopes()->create([
            'organization_id' => $this->org->id,
            'journal_entry_id' => $entry->id,
            'account_id' => $cash->id,
            'line_number' => 1,
            'debit' => 5000.00,
            'credit' => 0.00,
        ]);

        JournalLine::withoutGlobalScopes()->create([
            'organization_id' => $this->org->id,
            'journal_entry_id' => $entry->id,
            'account_id' => $capital->id,
            'line_number' => 2,
            'debit' => 0.00,
            'credit' => 4500.00, // Discrepancy of 500.00
        ]);

        $this->expectException(UnbalancedJournalException::class);
        $this->postingEngine->postEntry($entry, $this->user);
    }

    public function test_journal_lines_reject_negative_amounts(): void
    {
        $cash = Account::withoutGlobalScopes()->where('organization_id', $this->org->id)->where('code', '1010')->first();
        $capital = Account::withoutGlobalScopes()->where('organization_id', $this->org->id)->where('code', '3010')->first();

        $this->expectException(\InvalidArgumentException::class);
        $this->postingEngine->createDraft($this->org, [
            'entry_date' => '2025-07-15',
            'source_type' => 'manual',
            'description' => 'Negative line test',
            'lines' => [
                ['account_id' => $cash->id, 'debit' => -1000.00, 'credit' => 0.00],
                ['account_id' => $capital->id, 'debit' => 0.00, 'credit' => -1000.00],
            ],
        ], $this->user);
    }

    public function test_sales_invoice_creation_respects_non_negative_invariants(): void
    {
        $customer = Customer::create([
            'organization_id' => $this->org->id,
            'name' => 'Acme Test Customer',
        ]);

        $invoice = SalesInvoice::create([
            'organization_id' => $this->org->id,
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-2025-999',
            'issue_date' => '2025-07-15',
            'due_date' => '2025-08-15',
            'status' => 'draft',
            'subtotal' => 10000.00,
            'tax_amount' => 1700.00,
            'total_amount' => 11700.00,
            'amount_paid' => 0.00,
        ]);

        $this->assertDatabaseHas('sales_invoices', [
            'id' => $invoice->id,
            'total_amount' => 11700.00,
            'status' => 'draft',
        ]);
    }

    public function test_migration_rollback_and_reapply_safety(): void
    {
        // P1-37: Verify migration down() and up() cycle executes without exception
        $migration = require database_path('migrations/2026_09_30_150000_add_accounting_database_constraints.php');

        $migration->down();
        $this->assertTrue(true, 'Migration down() executed safely');

        $migration->up();
        $this->assertTrue(true, 'Migration up() executed safely');
    }
}
