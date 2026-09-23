<?php

namespace App\Domain\Consolidation\Services;

use App\Domain\Accounting\ChartOfAccounts\Models\Account;
use App\Domain\Accounting\Journal\Models\JournalEntry;
use App\Domain\Accounting\Journal\Models\JournalLine;
use App\Domain\Accounting\Period\Models\AccountingPeriod;
use App\Domain\Accounting\Posting\Services\PostingEngine;
use App\Domain\Audit\Services\AuditService;
use App\Domain\Consolidation\Models\IntercompanyTransaction;
use App\Domain\Organization\Models\Entity;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class ConsolidationService
{
    public function __construct(
        private readonly PostingEngine $postingEngine,
        private readonly CurrencyService $currencyService,
        private readonly ?AuditService $auditService = null
    ) {
    }

    /**
     * Create an intercompany transaction between two distinct legal entities.
     */
    public function createIntercompanyTransaction(Organization $organization, array $data, User $user): IntercompanyTransaction
    {
        $fromEntityId = $data['from_entity_id'];
        $toEntityId = $data['to_entity_id'];

        if ($fromEntityId === $toEntityId) {
            throw new InvalidArgumentException("Intercompany transactions require two distinct legal entities.");
        }

        $fromEntity = Entity::withoutGlobalScopes()->where('organization_id', $organization->id)->findOrFail($fromEntityId);
        $toEntity = Entity::withoutGlobalScopes()->where('organization_id', $organization->id)->findOrFail($toEntityId);

        $date = Carbon::parse($data['transaction_date']);
        $currency = strtoupper($data['currency'] ?? $fromEntity->currency ?? 'PKR');
        $amount = (float) $data['amount'];

        $exchangeRate = (float) ($data['exchange_rate'] ?? $this->currencyService->getExchangeRate($organization, $currency, $organization->base_currency ?? 'PKR', $date));
        $baseAmount = round($amount * $exchangeRate, 4);

        $seq = IntercompanyTransaction::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->count() + 1;
        $txNumber = sprintf('IC-%s-%04d', $date->format('Y'), $seq);

        return IntercompanyTransaction::create([
            'organization_id' => $organization->id,
            'from_entity_id' => $fromEntity->id,
            'to_entity_id' => $toEntity->id,
            'transaction_number' => $txNumber,
            'transaction_date' => $date->toDateString(),
            'currency' => $currency,
            'amount' => $amount,
            'exchange_rate' => $exchangeRate,
            'base_amount' => $baseAmount,
            'description' => $data['description'],
            'status' => 'draft',
            'created_by' => $user->id,
        ]);
    }

    /**
     * Post an intercompany transaction to the General Ledger of both participating entities.
     */
    public function postIntercompanyTransaction(IntercompanyTransaction $tx, User $user): IntercompanyTransaction
    {
        if ($tx->status !== 'draft') {
            throw new InvalidArgumentException("Only draft intercompany transactions can be posted.");
        }

        $organization = Organization::findOrFail($tx->organization_id);
        $txDate = Carbon::parse($tx->transaction_date);

        // Resolve AR and AP accounts
        $arAccount = Account::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->where('code', '1030')
            ->firstOrFail();

        $apAccount = Account::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->where('code', '2010')
            ->firstOrFail();

        $revAccount = Account::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->where('code', '4010')
            ->firstOrFail();

        $expAccount = Account::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->where('code', '6070')
            ->firstOrFail();

        return DB::transaction(function () use ($organization, $tx, $txDate, $arAccount, $apAccount, $revAccount, $expAccount, $user) {
            // 1. From Entity Journal: Debit Intercompany AR, Credit Revenue
            $fromDraft = $this->postingEngine->createDraft($organization, [
                'entity_id' => $tx->from_entity_id,
                'entry_date' => $txDate->toDateString(),
                'source_type' => 'intercompany',
                'source_id' => $tx->id,
                'description' => "Intercompany Billing to {$tx->toEntity->name}: {$tx->description}",
                'currency' => $tx->currency,
                'exchange_rate' => $tx->exchange_rate,
                'lines' => [
                    ['account_id' => $arAccount->id, 'debit' => (float) $tx->base_amount, 'credit' => 0.0000, 'description' => "IC Due from {$tx->toEntity->name}"],
                    ['account_id' => $revAccount->id, 'debit' => 0.0000, 'credit' => (float) $tx->base_amount, 'description' => "IC Revenue from {$tx->toEntity->name}"],
                ],
            ], $user);
            $fromPosted = $this->postingEngine->postEntry($fromDraft, $user);

            // 2. To Entity Journal: Debit Expense, Credit Intercompany AP
            $toDraft = $this->postingEngine->createDraft($organization, [
                'entity_id' => $tx->to_entity_id,
                'entry_date' => $txDate->toDateString(),
                'source_type' => 'intercompany',
                'source_id' => $tx->id,
                'description' => "Intercompany Charge from {$tx->fromEntity->name}: {$tx->description}",
                'currency' => $tx->currency,
                'exchange_rate' => $tx->exchange_rate,
                'lines' => [
                    ['account_id' => $expAccount->id, 'debit' => (float) $tx->base_amount, 'credit' => 0.0000, 'description' => "IC Shared Service Expense"],
                    ['account_id' => $apAccount->id, 'debit' => 0.0000, 'credit' => (float) $tx->base_amount, 'description' => "IC Payable to {$tx->fromEntity->name}"],
                ],
            ], $user);
            $toPosted = $this->postingEngine->postEntry($toDraft, $user);

            $tx->update([
                'status' => 'posted',
                'from_journal_entry_id' => $fromPosted->id,
                'to_journal_entry_id' => $toPosted->id,
            ]);

            return $tx->fresh(['fromEntity', 'toEntity', 'fromJournal', 'toJournal']);
        });
    }

    /**
     * Run period intercompany elimination routine.
     * Eliminates reciprocal intercompany payables and receivables across entities.
     */
    public function eliminateIntercompany(Organization $organization, AccountingPeriod $period, User $user): array
    {
        $transactions = IntercompanyTransaction::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->where('status', 'posted')
            ->whereBetween('transaction_date', [$period->start_date->toDateString(), $period->end_date->toDateString()])
            ->get();

        if ($transactions->isEmpty()) {
            return [
                'eliminated' => false,
                'message' => 'No posted intercompany transactions found for elimination in this period.',
                'total_amount' => 0.00,
                'count' => 0,
            ];
        }

        $totalEliminated = (float) $transactions->sum('base_amount');

        $arAccount = Account::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->where('code', '1030')
            ->firstOrFail();

        $apAccount = Account::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->where('code', '2010')
            ->firstOrFail();

        // Balanced Elimination Entry: Debit Intercompany AP, Credit Intercompany AR
        $eliminationLines = [
            [
                'account_id' => $apAccount->id,
                'description' => "Elimination of Intercompany Payables - Period {$period->name}",
                'debit' => $totalEliminated,
                'credit' => 0.0000,
            ],
            [
                'account_id' => $arAccount->id,
                'description' => "Elimination of Intercompany Receivables - Period {$period->name}",
                'debit' => 0.0000,
                'credit' => $totalEliminated,
            ],
        ];

        return DB::transaction(function () use ($organization, $period, $transactions, $eliminationLines, $totalEliminated, $user) {
            $draft = $this->postingEngine->createDraft($organization, [
                'entry_date' => $period->end_date->toDateString(),
                'accounting_period_id' => $period->id,
                'source_type' => 'elimination',
                'description' => "Consolidated Intercompany Eliminations - Period {$period->name}",
                'currency' => $organization->base_currency ?? 'PKR',
                'lines' => $eliminationLines,
            ], $user);

            $postedJournal = $this->postingEngine->postEntry($draft, $user);

            foreach ($transactions as $tx) {
                $tx->update([
                    'status' => 'eliminated',
                    'eliminated_at' => now(),
                    'elimination_journal_entry_id' => $postedJournal->id,
                ]);
            }

            if (class_exists(AuditService::class)) {
                $auditService = $this->auditService ?? app(AuditService::class);
                $auditService->log(
                    $organization->id,
                    $user,
                    'intercompany:eliminated',
                    $postedJournal,
                    null,
                    [
                        'total_eliminated' => $totalEliminated,
                        'transactions_count' => $transactions->count(),
                        'period' => $period->name,
                    ]
                );
            }

            return [
                'eliminated' => true,
                'elimination_journal_id' => $postedJournal->id,
                'elimination_journal_number' => $postedJournal->entry_number,
                'total_amount' => $totalEliminated,
                'transactions_count' => $transactions->count(),
                'period' => $period->name,
            ];
        });
    }

    /**
     * Generate Consolidated Financial Reports with entity breakdowns and eliminations.
     */
    public function getConsolidatedReport(Organization $organization, string $reportType, AccountingPeriod $period): array
    {
        $entities = Entity::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->where('status', 'active')
            ->get();

        $accounts = Account::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->where('is_active', true)
            ->orderBy('code')
            ->get();

        $rows = [];
        $totalDebit = 0.0;
        $totalCredit = 0.0;

        foreach ($accounts as $account) {
            $entityBalances = [];
            $accountNetTotal = 0.0;

            foreach ($entities as $entity) {
                $net = (float) JournalLine::withoutGlobalScopes()
                    ->where('organization_id', $organization->id)
                    ->where('account_id', $account->id)
                    ->whereHas('journalEntry', function ($q) use ($period, $entity) {
                        $q->where('status', 'posted')
                          ->where('accounting_period_id', $period->id)
                          ->where('entity_id', $entity->id);
                    })
                    ->selectRaw('COALESCE(SUM(debit - credit), 0) as net')
                    ->value('net');

                $entityBalances[$entity->code] = $net;
                $accountNetTotal += $net;
            }

            // Also check entries with no specific entity (e.g. org-level eliminations)
            $eliminationNet = (float) JournalLine::withoutGlobalScopes()
                ->where('organization_id', $organization->id)
                ->where('account_id', $account->id)
                ->whereHas('journalEntry', function ($q) use ($period) {
                    $q->where('status', 'posted')
                      ->where('accounting_period_id', $period->id)
                      ->where('source_type', 'elimination');
                })
                ->selectRaw('COALESCE(SUM(debit - credit), 0) as net')
                ->value('net');

            $consolidatedTotal = $accountNetTotal + $eliminationNet;

            if ($consolidatedTotal >= 0) {
                $totalDebit += $consolidatedTotal;
            } else {
                $totalCredit += abs($consolidatedTotal);
            }

            $rows[] = [
                'account_code' => $account->code,
                'account_name' => $account->name,
                'classification' => $account->classification,
                'entity_breakdown' => $entityBalances,
                'eliminations' => $eliminationNet,
                'consolidated_balance' => $consolidatedTotal,
            ];
        }

        return [
            'organization_id' => $organization->id,
            'report_type' => $reportType,
            'period' => [
                'id' => $period->id,
                'name' => $period->name,
            ],
            'entities' => $entities->map(fn ($e) => ['id' => $e->id, 'name' => $e->name, 'code' => $e->code, 'currency' => $e->currency]),
            'total_debit' => round($totalDebit, 4),
            'total_credit' => round($totalCredit, 4),
            'is_balanced' => abs($totalDebit - $totalCredit) < 0.01,
            'items' => $rows,
        ];
    }
}
