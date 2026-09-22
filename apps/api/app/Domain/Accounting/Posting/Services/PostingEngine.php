<?php

namespace App\Domain\Accounting\Posting\Services;

use App\Domain\Accounting\Journal\Models\JournalEntry;
use App\Domain\Accounting\Journal\Models\JournalLine;
use App\Domain\Accounting\Period\Models\AccountingPeriod;
use App\Domain\Accounting\Period\Services\PeriodManager;
use App\Domain\Accounting\Posting\Exceptions\ClosedPeriodException;
use App\Domain\Accounting\Posting\Exceptions\ImmutableJournalException;
use App\Domain\Accounting\Posting\Exceptions\UnbalancedJournalException;
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
     */
    public function generateEntryNumber(Organization $organization, Carbon $date): string
    {
        $year = $date->format('Y');
        $prefix = "JE-{$year}-";

        $lastEntry = JournalEntry::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->where('entry_number', 'LIKE', "{$prefix}%")
            ->orderBy('entry_number', 'desc')
            ->first();

        if ($lastEntry) {
            $lastSequence = (int) substr($lastEntry->entry_number, strlen($prefix));
            $nextSequence = $lastSequence + 1;
        } else {
            $nextSequence = 1;
        }

        return $prefix . str_pad((string) $nextSequence, 5, '0', STR_PAD_LEFT);
    }

    /**
     * Create a new draft journal entry with lines.
     */
    public function createDraft(Organization $organization, array $data, User $user): JournalEntry
    {
        $entryDate = Carbon::parse($data['entry_date']);

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

        $entryNumber = $data['entry_number'] ?? $this->generateEntryNumber($organization, $entryDate);

        return DB::transaction(function () use ($organization, $period, $data, $entryDate, $entryNumber, $user) {
            $entry = JournalEntry::withoutGlobalScopes()->create([
                'organization_id' => $organization->id,
                'accounting_period_id' => $period->id,
                'entry_number' => $entryNumber,
                'entry_date' => $entryDate->toDateString(),
                'status' => 'draft',
                'source_type' => $data['source_type'] ?? 'manual',
                'source_id' => $data['source_id'] ?? null,
                'description' => $data['description'],
                'currency' => $data['currency'] ?? $organization->base_currency ?? 'PKR',
                'exchange_rate' => $data['exchange_rate'] ?? 1.000000,
                'created_by' => $user->id,
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
        });
    }

    /**
     * Update an existing draft journal entry.
     */
    public function updateDraft(JournalEntry $entry, array $data, User $user): JournalEntry
    {
        if (! $entry->isDraft()) {
            throw new ImmutableJournalException($entry->entry_number);
        }

        return DB::transaction(function () use ($entry, $data) {
            if (isset($data['description'])) {
                $entry->description = $data['description'];
            }
            if (isset($data['entry_date'])) {
                $entry->entry_date = $data['entry_date'];
            }
            $entry->save();

            if (isset($data['lines'])) {
                $entry->lines()->delete();

                $lineNumber = 1;
                foreach ($data['lines'] as $lineData) {
                    JournalLine::withoutGlobalScopes()->create([
                        'organization_id' => $entry->organization_id,
                        'journal_entry_id' => $entry->id,
                        'account_id' => $lineData['account_id'],
                        'line_number' => $lineNumber++,
                        'description' => $lineData['description'] ?? null,
                        'debit' => $lineData['debit'] ?? 0.0000,
                        'credit' => $lineData['credit'] ?? 0.0000,
                        'currency' => $lineData['currency'] ?? $entry->currency,
                    ]);
                }
            }

            return $entry->load(['lines.account', 'period']);
        });
    }

    /**
     * Post a draft journal entry to the General Ledger.
     */
    public function postEntry(JournalEntry $entry, User $user): JournalEntry
    {
        if (! $entry->isDraft()) {
            throw new ImmutableJournalException($entry->entry_number);
        }

        $entry->load('lines');

        // Invariant 1: Total Debit == Total Credit
        $totalDebit = $entry->totalDebit();
        $totalCredit = $entry->totalCredit();

        if (abs($totalDebit - $totalCredit) >= 0.0001 || $totalDebit <= 0) {
            throw new UnbalancedJournalException($totalDebit, $totalCredit);
        }

        // Invariant 2: Period must be open and not closed/locked
        $period = $entry->period;
        if (! $period || ! $period->canPost()) {
            throw new ClosedPeriodException($period?->name ?? 'Unknown Period', $period?->status ?? 'closed');
        }

        $entry->update([
            'status' => 'posted',
            'posted_at' => now(),
            'posted_by' => $user->id,
            'total_amount' => $totalDebit,
        ]);

        return $entry->fresh(['lines.account', 'period', 'postedByUser']);
    }

    /**
     * Create and post a reversal entry for a posted journal entry.
     */
    public function createReversal(JournalEntry $originalEntry, User $user, ?string $reason = null): JournalEntry
    {
        if (! $originalEntry->isPosted()) {
            throw new InvalidArgumentException("Only posted journal entries can be reversed.");
        }

        $organization = Organization::findOrFail($originalEntry->organization_id);
        $reversalDate = now();
        $period = $this->periodManager->getOpenPeriodForDate($organization, $reversalDate);

        if (! $period) {
            // Fall back to original entry's period if open
            if ($originalEntry->period?->canPost()) {
                $period = $originalEntry->period;
            } else {
                throw new ClosedPeriodException("No open accounting period available to post reversal.");
            }
        }

        $entryNumber = $this->generateEntryNumber($organization, $reversalDate);

        return DB::transaction(function () use ($organization, $originalEntry, $period, $entryNumber, $reversalDate, $user, $reason) {
            $reversalEntry = JournalEntry::withoutGlobalScopes()->create([
                'organization_id' => $organization->id,
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
