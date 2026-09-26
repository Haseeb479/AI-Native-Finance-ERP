<?php

namespace App\Domain\Accounting\Posting\Services;

use App\Domain\Accounting\ChartOfAccounts\Models\Account;
use App\Domain\Accounting\Journal\Models\JournalEntry;
use App\Domain\Accounting\Journal\Models\JournalLine;
use App\Domain\Accounting\Journal\Models\JournalSequence;
use App\Domain\Accounting\Period\Models\AccountingPeriod;
use App\Domain\Accounting\Period\Services\PeriodManager;
use App\Domain\Accounting\Posting\Exceptions\ClosedPeriodException;
use App\Domain\Accounting\Posting\Exceptions\ControlAccountProtectedException;
use App\Domain\Accounting\Posting\Exceptions\ImmutableJournalException;
use App\Domain\Accounting\Posting\Exceptions\UnbalancedJournalException;
use App\Domain\Organization\Models\Entity;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PostingEngine
{
    public function __construct(private readonly PeriodManager $periodManager)
    {
    }

    /**
     * Generate sequential human-readable entry number per tenant (e.g. JE-2025-00001).
     * Concurrency-safe: uses atomic row-level locking on dedicated journal_sequences table.
     */
    public function generateEntryNumber(Organization $organization, Carbon $date): string
    {
        $year = $date->format('Y');
        $prefix = "JE-{$year}-";

        return DB::transaction(function () use ($organization, $prefix) {
            $seq = JournalSequence::where('organization_id', $organization->id)
                ->where('prefix', $prefix)
                ->lockForUpdate()
                ->first();

            if (! $seq) {
                // Determine highest existing sequence from journal_entries to seed accurately
                $lastEntry = JournalEntry::withoutGlobalScopes()
                    ->where('organization_id', $organization->id)
                    ->where('entry_number', 'LIKE', "{$prefix}%")
                    ->orderBy('entry_number', 'desc')
                    ->first();

                $initialSequence = $lastEntry
                    ? (int) substr($lastEntry->entry_number, strlen($prefix))
                    : 0;

                $seq = JournalSequence::create([
                    'organization_id' => $organization->id,
                    'prefix' => $prefix,
                    'current_sequence' => $initialSequence,
                ]);

                // Re-lock the newly created sequence row
                $seq = JournalSequence::where('id', $seq->id)->lockForUpdate()->first();
            }

            $seq->current_sequence++;
            $seq->save();

            return $prefix . str_pad((string) $seq->current_sequence, 5, '0', STR_PAD_LEFT);
        });
    }

    /**
     * Create a new draft journal entry with lines.
     */
    public function createDraft(Organization $organization, array $data, User $user): JournalEntry
    {
        // Idempotency guard for financial mutations (P1 Tier 1)
        if (! empty($data['idempotency_key'])) {
            $existing = JournalEntry::withoutGlobalScopes()
                ->where('organization_id', $organization->id)
                ->where('idempotency_key', $data['idempotency_key'])
                ->first();

            if ($existing) {
                return $existing->load(['lines.account', 'period']);
            }
        }

        $entryDate = Carbon::parse($data['entry_date']);

        // P0-08: Verify entity tenant consistency
        if (! empty($data['entity_id'])) {
            $entityExists = Entity::withoutGlobalScopes()
                ->where('id', $data['entity_id'])
                ->where('organization_id', $organization->id)
                ->exists();
            if (! $entityExists) {
                throw new InvalidArgumentException("Entity does not belong to organization {$organization->id}.");
            }
        }

        // Resolve accounting period for entry date
        $period = null;
        if (! empty($data['accounting_period_id'])) {
            $period = AccountingPeriod::withoutGlobalScopes()
                ->where('organization_id', $organization->id)
                ->findOrFail($data['accounting_period_id']);
        } else {
            $period = $this->periodManager->getOpenPeriodForDate($organization, $entryDate);
            if (! $period) {
                // If no open period, locate any period for the date to associate
                $period = AccountingPeriod::withoutGlobalScopes()
                    ->where('organization_id', $organization->id)
                    ->forDate($entryDate)
                    ->first();
            }
        }

        if (! $period) {
            throw new InvalidArgumentException("No accounting period defined covering date {$entryDate->toDateString()}.");
        }

        // P0-07 & P0-08: Invariant - period must belong to organization and cover entry date
        if ($period->organization_id !== $organization->id) {
            throw new InvalidArgumentException("Accounting period does not belong to organization.");
        }
        if (! $period->containsDate($entryDate)) {
            throw new InvalidArgumentException("Accounting period '{$period->name}' does not cover entry date {$entryDate->toDateString()}.");
        }

        $sourceType = $data['source_type'] ?? 'manual';
        $this->validateJournalLines($data['lines'] ?? [], $sourceType, $organization);

        $exchangeRate = (float) ($data['exchange_rate'] ?? 1.000000);
        if ($exchangeRate <= 0) {
            throw new InvalidArgumentException("Exchange rate must be strictly positive.");
        }

        // P0-06: Concurrency-safe entry number generation inside transaction with retry on unique collision
        return DB::transaction(function () use ($organization, $period, $data, $entryDate, $sourceType, $user, $exchangeRate) {
            $entryNumber = $data['entry_number'] ?? $this->generateEntryNumber($organization, $entryDate);

            $entry = JournalEntry::withoutGlobalScopes()->create([
                'organization_id' => $organization->id,
                'entity_id' => $data['entity_id'] ?? null,
                'accounting_period_id' => $period->id,
                'entry_number' => $entryNumber,
                'entry_date' => $entryDate->toDateString(),
                'status' => 'draft',
                'source_type' => $sourceType,
                'source_id' => $data['source_id'] ?? null,
                'description' => $data['description'],
                'currency' => $data['currency'] ?? $organization->base_currency ?? 'PKR',
                'exchange_rate' => $exchangeRate,
                'created_by' => $user->id,
                'idempotency_key' => $data['idempotency_key'] ?? null,
            ]);

            $lineNumber = 1;
            foreach ($data['lines'] as $lineData) {
                JournalLine::withoutGlobalScopes()->create([
                    'organization_id' => $organization->id,
                    'journal_entry_id' => $entry->id,
                    'account_id' => $lineData['account_id'],
                    'line_number' => $lineNumber++,
                    'description' => $lineData['description'] ?? null,
                    'debit' => $lineData['debit'] ?? 0.0000,
                    'credit' => $lineData['credit'] ?? 0.0000,
                    'currency' => $lineData['currency'] ?? $entry->currency,
                ]);
            }

            return $entry->load(['lines.account', 'period']);
        }, 5);
    }

    /**
     * Update an existing draft journal entry.
     */
    public function updateDraft(JournalEntry $entry, array $data, User $user): JournalEntry
    {
        return DB::transaction(function () use ($entry, $data) {
            $lockedEntry = JournalEntry::withoutGlobalScopes()
                ->where('id', $entry->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $lockedEntry->isDraft()) {
                throw new ImmutableJournalException($lockedEntry->entry_number);
            }

            $organization = Organization::findOrFail($lockedEntry->organization_id);

            if (isset($data['entry_date'])) {
                $newDate = Carbon::parse($data['entry_date']);
                $lockedEntry->entry_date = $newDate;
                if ($lockedEntry->period && ! $lockedEntry->period->containsDate($newDate)) {
                    $newPeriod = $this->periodManager->getOpenPeriodForDate($organization, $newDate)
                        ?? AccountingPeriod::withoutGlobalScopes()->where('organization_id', $organization->id)->forDate($newDate)->first();
                    if ($newPeriod) {
                        $lockedEntry->accounting_period_id = $newPeriod->id;
                    }
                }
            }

            if (isset($data['accounting_period_id'])) {
                $period = AccountingPeriod::withoutGlobalScopes()
                    ->where('organization_id', $lockedEntry->organization_id)
                    ->findOrFail($data['accounting_period_id']);
                if (! $period->containsDate($lockedEntry->entry_date)) {
                    throw new InvalidArgumentException("Accounting period does not cover entry date.");
                }
                $lockedEntry->accounting_period_id = $period->id;
            }

            if (isset($data['lines'])) {
                $this->validateJournalLines($data['lines'], $lockedEntry->source_type, $organization);
            }

            if (isset($data['description'])) {
                $lockedEntry->description = $data['description'];
            }
            if (isset($data['exchange_rate'])) {
                $rate = (float) $data['exchange_rate'];
                if ($rate <= 0) {
                    throw new InvalidArgumentException("Exchange rate must be strictly positive.");
                }
                $lockedEntry->exchange_rate = $rate;
            }

            $lockedEntry->save();

            if (isset($data['lines'])) {
                $lockedEntry->lines()->delete();

                $lineNumber = 1;
                foreach ($data['lines'] as $lineData) {
                    JournalLine::withoutGlobalScopes()->create([
                        'organization_id' => $lockedEntry->organization_id,
                        'journal_entry_id' => $lockedEntry->id,
                        'account_id' => $lineData['account_id'],
                        'line_number' => $lineNumber++,
                        'description' => $lineData['description'] ?? null,
                        'debit' => $lineData['debit'] ?? 0.0000,
                        'credit' => $lineData['credit'] ?? 0.0000,
                        'currency' => $lineData['currency'] ?? $lockedEntry->currency,
                    ]);
                }
            }

            return $lockedEntry->load(['lines.account', 'period']);
        });
    }

    /**
     * Validate journal lines for double-entry mathematical invariants, tenant consistency, and control account restrictions.
     */
    private function validateJournalLines(array $lines, string $sourceType, ?Organization $organization = null): void
    {
        // P0-07: Invariant - at least 2 lines for double-entry balance
        if (count($lines) < 2) {
            throw new InvalidArgumentException("Journal entry must have at least 2 lines for double-entry balance.");
        }

        $accountIds = array_filter(array_column($lines, 'account_id'));
        $accountsQuery = Account::withoutGlobalScopes()->whereIn('id', $accountIds);
        if ($organization) {
            $accountsQuery->where('organization_id', $organization->id);
        }
        $accounts = $accountsQuery->get()->keyBy('id');

        foreach ($lines as $line) {
            $debit = (string) ($line['debit'] ?? '0.0000');
            $credit = (string) ($line['credit'] ?? '0.0000');

            // Invariant: Non-negative amounts
            if (bccomp($debit, '0.0000', 4) < 0 || bccomp($credit, '0.0000', 4) < 0) {
                throw new InvalidArgumentException("Journal line amounts must be strictly non-negative.");
            }

            // Invariant: Mutually exclusive debit and credit
            if (bccomp($debit, '0.0000', 4) > 0 && bccomp($credit, '0.0000', 4) > 0) {
                throw new InvalidArgumentException("Journal line cannot have both debit and credit amounts. Debit and credit must be mutually exclusive.");
            }

            // Invariant: Amount must be non-zero
            if (bccomp($debit, '0.0000', 4) === 0 && bccomp($credit, '0.0000', 4) === 0) {
                throw new InvalidArgumentException("Journal line amount must be greater than zero.");
            }

            if (! isset($line['account_id'])) {
                throw new InvalidArgumentException("Account ID is required for each journal line.");
            }

            $account = $accounts->get($line['account_id']);
            if (! $account) {
                throw new InvalidArgumentException("Account {$line['account_id']} not found or does not belong to this organization.");
            }

            // P0-07 / P0-08: Account must belong to organization and be active
            if ($organization && $account->organization_id !== $organization->id) {
                throw new InvalidArgumentException("Account {$account->code} does not belong to organization {$organization->id}.");
            }

            if (isset($account->is_active) && ! $account->is_active) {
                throw new InvalidArgumentException("Cannot post to inactive account {$account->code}.");
            }

            if ($sourceType === 'manual' && $account->isControlAccount()) {
                throw new ControlAccountProtectedException(
                    $account->code,
                    $account->name,
                    $account->control_type ?? 'subledger'
                );
            }
        }
    }

    /**
     * Post a draft journal entry to the General Ledger.
     * Concurrency-safe: row-level locking with SELECT FOR UPDATE, idempotent re-posting check,
     * period re-check inside transaction, and tenant consistency validation.
     */
    public function postEntry(JournalEntry $entry, User $user): JournalEntry
    {
        return DB::transaction(function () use ($entry, $user) {
            // P0-05: Concurrency-safe row-level lock
            $lockedEntry = JournalEntry::withoutGlobalScopes()
                ->where('id', $entry->id)
                ->lockForUpdate()
                ->firstOrFail();

            // P0-05: Idempotency check — second concurrent call returns posted entry safely
            if ($lockedEntry->isPosted()) {
                return $lockedEntry->fresh(['lines.account', 'period', 'postedByUser']);
            }

            if (! $lockedEntry->isDraft()) {
                throw new ImmutableJournalException($lockedEntry->entry_number);
            }

            $lockedEntry->load(['lines.account', 'period']);

            // P0-07: Invariant: minimum 2 lines
            if ($lockedEntry->lines->count() < 2) {
                throw new InvalidArgumentException("Journal entry must have at least 2 lines to post.");
            }

            // Invariant 1: Total Debit == Total Credit and Total Debit > 0 (arbitrary-precision bcmath)
            $totalDebitStr = $lockedEntry->totalDebitString();
            $totalCreditStr = $lockedEntry->totalCreditString();

            if (bccomp($totalDebitStr, $totalCreditStr, 4) !== 0 || bccomp($totalDebitStr, '0.0000', 4) <= 0) {
                throw new UnbalancedJournalException((float) $totalDebitStr, (float) $totalCreditStr);
            }

            // P0-08: Tenant consistency below controllers
            foreach ($lockedEntry->lines as $line) {
                if ($line->organization_id !== $lockedEntry->organization_id) {
                    throw new InvalidArgumentException("Journal line organization does not match journal entry organization.");
                }
                if (! $line->account || $line->account->organization_id !== $lockedEntry->organization_id) {
                    throw new InvalidArgumentException("Account does not belong to the journal entry organization.");
                }
                if (isset($line->account->is_active) && ! $line->account->is_active) {
                    throw new InvalidArgumentException("Cannot post to inactive account {$line->account->code}.");
                }
            }

            if ($lockedEntry->entity_id) {
                $entityExists = Entity::withoutGlobalScopes()
                    ->where('id', $lockedEntry->entity_id)
                    ->where('organization_id', $lockedEntry->organization_id)
                    ->exists();
                if (! $entityExists) {
                    throw new InvalidArgumentException("Entity does not belong to the journal entry organization.");
                }
            }

            // P0-05: Two-stage accounting period validation locked inside transaction
            $period = AccountingPeriod::withoutGlobalScopes()
                ->where('id', $lockedEntry->accounting_period_id)
                ->lockForUpdate()
                ->first();

            if (! $period || $period->organization_id !== $lockedEntry->organization_id) {
                throw new ClosedPeriodException('Unknown Period', 'closed');
            }

            if (! $period->containsDate($lockedEntry->entry_date)) {
                throw new ClosedPeriodException("Accounting period '{$period->name}' does not cover entry date {$lockedEntry->entry_date->toDateString()}.", $period->status);
            }

            if ($period->isHardClosed()) {
                throw new ClosedPeriodException($period->name, $period->status);
            }

            if ($period->isSoftClosed()) {
                $operationalSources = [
                    'invoice', 'sales_invoice', 'bill', 'vendor_bill',
                    'customer_payment', 'vendor_payment', 'payment',
                    'cogs', 'bank', 'customer_receipt', 'vendor_disbursement',
                ];

                if (in_array($lockedEntry->source_type, $operationalSources, true)) {
                    throw new ClosedPeriodException(
                        sprintf("Accounting period '%s' is soft-closed. Operational subledger postings (%s) are locked.", $period->name, $lockedEntry->source_type),
                        $period->status
                    );
                }
            } elseif (! $period->isOpen()) {
                throw new ClosedPeriodException($period->name, $period->status);
            }

            $lockedEntry->update([
                'status' => 'posted',
                'posted_at' => now(),
                'posted_by' => $user->id,
                'total_amount' => $totalDebitStr,
            ]);

            if (class_exists(\App\Domain\Audit\Services\AuditService::class)) {
                app(\App\Domain\Audit\Services\AuditService::class)->log(
                    $lockedEntry->organization_id,
                    $user,
                    'journal:posted',
                    $lockedEntry,
                    ['status' => 'draft'],
                    [
                        'status' => 'posted',
                        'entry_number' => $lockedEntry->entry_number,
                        'total_amount' => $totalDebitStr,
                        'posted_at' => $lockedEntry->posted_at->toIso8601String(),
                    ]
                );
            }

            return $lockedEntry->fresh(['lines.account', 'period', 'postedByUser']);
        });
    }

    /**
     * Create and post a reversal entry for a posted journal entry.
     */
    public function createReversal(JournalEntry $originalEntry, User $user, ?string $reason = null): JournalEntry
    {
        if (! $originalEntry->isPosted()) {
            throw new InvalidArgumentException("Only posted journal entries can be reversed.");
        }

        // Prevent double reversal
        $alreadyReversed = JournalEntry::withoutGlobalScopes()
            ->where('reversal_of_id', $originalEntry->id)
            ->whereIn('status', ['draft', 'posted'])
            ->exists();
        if ($alreadyReversed) {
            throw new InvalidArgumentException("Journal entry {$originalEntry->entry_number} has already been reversed.");
        }

        $organization = Organization::findOrFail($originalEntry->organization_id);
        $reversalDate = now();
        $period = $this->periodManager->getOpenPeriodForDate($organization, $reversalDate);

        if (! $period) {
            // Fall back to original entry's period if open
            if ($originalEntry->period?->canPost()) {
                $period = $originalEntry->period;
                $reversalDate = Carbon::parse($originalEntry->entry_date);
            } else {
                throw new ClosedPeriodException("No open accounting period available to post reversal.");
            }
        }

        return DB::transaction(function () use ($organization, $originalEntry, $period, $reversalDate, $user, $reason) {
            $entryNumber = $this->generateEntryNumber($organization, $reversalDate);

            $reversalEntry = JournalEntry::withoutGlobalScopes()->create([
                'organization_id' => $organization->id,
                'entity_id' => $originalEntry->entity_id,
                'accounting_period_id' => $period->id,
                'entry_number' => $entryNumber,
                'entry_date' => $reversalDate->toDateString(),
                'status' => 'draft',
                'source_type' => 'reversal',
                'source_id' => $originalEntry->id,
                'reversal_of_id' => $originalEntry->id,
                'description' => "Reversal of {$originalEntry->entry_number}: " . ($reason ?? 'Correction entry'),
                'currency' => $originalEntry->currency,
                'exchange_rate' => $originalEntry->exchange_rate,
                'created_by' => $user->id,
            ]);

            $lineNumber = 1;
            foreach ($originalEntry->lines as $line) {
                // Invert debits and credits
                JournalLine::withoutGlobalScopes()->create([
                    'organization_id' => $organization->id,
                    'journal_entry_id' => $reversalEntry->id,
                    'account_id' => $line->account_id,
                    'line_number' => $lineNumber++,
                    'description' => "Reversal: " . ($line->description ?? "Line {$line->line_number}"),
                    'debit' => $line->credit, // Swapped
                    'credit' => $line->debit, // Swapped
                    'currency' => $line->currency,
                ]);
            }

            // Post immediately
            return $this->postEntry($reversalEntry, $user);
        });
    }
}
