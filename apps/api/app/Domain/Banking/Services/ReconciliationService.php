<?php

namespace App\Domain\Banking\Services;

use App\Domain\Accounting\Journal\Models\JournalEntry;
use App\Domain\Accounting\Journal\Models\JournalLine;
use App\Domain\Banking\Models\BankAccount;
use App\Domain\Banking\Models\BankStatement;
use App\Domain\Banking\Models\BankTransaction;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ReconciliationService
{
    public function __construct(
        protected BankStatementParser $parser
    ) {}

    /**
     * Import and normalize a CSV statement into BankStatement and BankTransaction records.
     * Prevents duplicate transactions using deterministic SHA256 fingerprints.
     */
    public function importStatement(
        BankAccount $bankAccount,
        string $csvContent,
        string $filename = 'statement.csv',
        ?User $user = null
    ): BankStatement {
        $parsed = $this->parser->parseCsv($csvContent);

        return DB::transaction(function () use ($bankAccount, $parsed, $filename, $user) {
            $statementNumber = 'STMT-' . Carbon::parse($parsed['to_date'])->format('Ym') . '-' . str_pad((string) (BankStatement::where('bank_account_id', $bankAccount->id)->count() + 1), 4, '0', STR_PAD_LEFT);

            $statement = BankStatement::create([
                'organization_id' => $bankAccount->organization_id,
                'bank_account_id' => $bankAccount->id,
                'statement_number' => $statementNumber,
                'from_date' => $parsed['from_date'],
                'to_date' => $parsed['to_date'],
                'opening_balance' => $parsed['opening_balance'],
                'closing_balance' => $parsed['closing_balance'],
                'source_file_name' => $filename,
                'status' => 'imported',
                'uploaded_by' => $user?->id,
            ]);

            $importedCount = 0;

            foreach ($parsed['transactions'] as $txData) {
                $fingerprint = BankTransaction::generateFingerprint(
                    $txData['date'],
                    (string) $txData['amount'],
                    $txData['reference'],
                    $txData['type']
                );

                // Deduplicate check
                $exists = BankTransaction::where('organization_id', $bankAccount->organization_id)
                    ->where('bank_account_id', $bankAccount->id)
                    ->where('fingerprint', $fingerprint)
                    ->exists();

                if ($exists) {
                    continue; // Skip duplicate transaction row
                }

                BankTransaction::create([
                    'organization_id' => $bankAccount->organization_id,
                    'bank_account_id' => $bankAccount->id,
                    'bank_statement_id' => $statement->id,
                    'transaction_date' => $txData['date'],
                    'description' => $txData['description'],
                    'reference' => $txData['reference'],
                    'type' => $txData['type'],
                    'amount' => $txData['amount'],
                    'balance_after' => $txData['balance_after'],
                    'reconciliation_status' => 'unreconciled',
                    'fingerprint' => $fingerprint,
                ]);

                $importedCount++;
            }

            // Update bank account current balance if closing balance was parsed
            if ($parsed['closing_balance'] > 0) {
                $bankAccount->update(['current_balance' => $parsed['closing_balance']]);
            }

            return $statement->load('transactions');
        });
    }

    /**
     * Find candidate posted GL Journal Entries for unreconciled transactions on this bank account.
     * Candidate matching criteria:
     * - Entry status is posted
     * - Entry has a line affecting this bank account's linked chart account (`bankAccount->account_id`)
     * - For bank deposit (credit): GL line is Debit to bank account
     * - For bank withdrawal (debit): GL line is Credit to bank account
     * - Amount matches exactly or reference matches
     *
     * @return array<string, array{
     *     transaction: BankTransaction,
     *     matches: array<int, array{journal_entry: JournalEntry, confidence: float, reason: string}>
     * }>
     */
    public function suggestMatches(BankAccount $bankAccount): array
    {
        $unreconciled = BankTransaction::where('organization_id', $bankAccount->organization_id)
            ->where('bank_account_id', $bankAccount->id)
            ->where('reconciliation_status', 'unreconciled')
            ->get();

        $suggestions = [];

        // Fetch posted journals touching this bank's GL account that haven't been reconciled
        $alreadyMatchedJournalIds = BankTransaction::where('organization_id', $bankAccount->organization_id)
            ->whereNotNull('matched_journal_entry_id')
            ->pluck('matched_journal_entry_id')
            ->toArray();

        $journalLines = JournalLine::where('account_id', $bankAccount->account_id)
            ->whereHas('journalEntry', function ($q) use ($bankAccount, $alreadyMatchedJournalIds) {
                $q->where('organization_id', $bankAccount->organization_id)
                    ->where('status', 'posted')
                    ->whereNotIn('id', $alreadyMatchedJournalIds);
            })
            ->with('journalEntry')
            ->get();

        foreach ($unreconciled as $tx) {
            $matches = [];
            $targetAmount = (float) $tx->amount;

            foreach ($journalLines as $line) {
                $entry = $line->journalEntry;
                $glAmount = $tx->type === 'credit' ? (float) $line->debit : (float) $line->credit;

                // Only consider lines moving in the right direction
                if ($glAmount <= 0) {
                    continue;
                }

                $confidence = 0.0;
                $reasons = [];

                // 1. Exact amount match
                if (abs($targetAmount - $glAmount) < 0.01) {
                    $confidence += 0.60;
                    $reasons[] = 'Exact amount match (' . number_format($glAmount, 2) . ')';
                }

                // 2. Reference / Cheque match
                $refMatched = false;
                if (!empty($tx->reference)) {
                    if (!empty($entry->reference) && strcasecmp(trim($tx->reference), trim($entry->reference)) === 0) {
                        $confidence += 0.30;
                        $reasons[] = "Exact reference match ({$tx->reference})";
                        $refMatched = true;
                    } elseif ((!empty($entry->reference) && stripos($entry->reference, $tx->reference) !== false)
                        || (!empty($entry->description) && stripos($entry->description, $tx->reference) !== false)) {
                        $confidence += 0.30;
                        $reasons[] = "Reference match in narration ({$tx->reference})";
                        $refMatched = true;
                    }
                }

                // 3. Date proximity (within 5 business days)
                $txDate = Carbon::parse($tx->transaction_date);
                $entryDate = Carbon::parse($entry->entry_date);
                $dayDiff = abs($txDate->diffInDays($entryDate));

                if ($dayDiff === 0) {
                    $confidence += 0.10;
                    $reasons[] = 'Same date';
                } elseif ($dayDiff <= 5) {
                    $confidence += 0.05;
                    $reasons[] = "Within {$dayDiff} days";
                }

                if ($confidence >= 0.50) {
                    $matches[] = [
                        'journal_entry' => $entry,
                        'confidence' => min(1.0, round($confidence, 2)),
                        'reason' => implode(', ', $reasons),
                    ];
                }
            }

            // Sort candidate matches by highest confidence
            usort($matches, fn($a, $b) => $b['confidence'] <=> $a['confidence']);

            $suggestions[] = [
                'transaction' => $tx,
                'matches' => $matches,
            ];
        }

        return $suggestions;
    }

    /**
     * Manually match a bank transaction to an existing posted JournalEntry.
     */
    public function matchTransaction(
        BankTransaction $transaction,
        JournalEntry $journalEntry,
        User $user
    ): BankTransaction {
        if ($transaction->organization_id !== $journalEntry->organization_id) {
            throw new InvalidArgumentException('Cross-tenant matching is forbidden.');
        }

        if (!$journalEntry->isPosted()) {
            throw new InvalidArgumentException('Cannot reconcile against an unposted journal entry.');
        }

        // Check if journal touches the bank's GL account
        $bankAccountId = $transaction->bankAccount->account_id;
        $hasBankLine = $journalEntry->lines()->where('account_id', $bankAccountId)->exists();
        if (!$hasBankLine) {
            throw new InvalidArgumentException('The selected journal entry does not affect this bank account.');
        }

        $transaction->update([
            'reconciliation_status' => 'reconciled',
            'matched_journal_entry_id' => $journalEntry->id,
            'matched_at' => now(),
            'matched_by' => $user->id,
        ]);

        return $transaction->load(['bankAccount', 'matchedJournalEntry']);
    }

    /**
     * Unmatch and revert transaction to unreconciled status.
     */
    public function unmatchTransaction(BankTransaction $transaction, User $user): BankTransaction
    {
        $transaction->update([
            'reconciliation_status' => 'unreconciled',
            'matched_journal_entry_id' => null,
            'matched_at' => null,
            'matched_by' => null,
        ]);

        return $transaction;
    }
}
