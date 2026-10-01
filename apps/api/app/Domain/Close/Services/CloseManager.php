<?php

namespace App\Domain\Close\Services;

use App\Domain\Accounting\ChartOfAccounts\Models\Account;
use App\Domain\Accounting\Journal\Models\JournalLine;
use App\Domain\Accounting\Period\Models\AccountingPeriod;
use App\Domain\Accounting\Posting\Services\PostingEngine;
use App\Domain\Audit\Services\AuditService;
use App\Domain\Banking\Models\BankTransaction;
use App\Domain\Close\Models\CloseCycle;
use App\Domain\Close\Models\CloseTask;
use App\Domain\Close\Models\FixedAsset;
use App\Domain\Organization\Models\Organization;
use App\Domain\Purchasing\Models\PurchaseBill;
use App\Domain\Sales\Models\SalesInvoice;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class CloseManager
{
    public function __construct(
        private readonly PostingEngine $postingEngine,
        private readonly ?AuditService $auditService = null
    ) {
    }

    /**
     * Get or initialize a close cycle with the standard 8-step month-end close checklist.
     */
    public function getOrCreateCloseCycle(Organization $organization, AccountingPeriod $period): CloseCycle
    {
        $cycle = CloseCycle::withoutGlobalScopes()->firstOrCreate(
            [
                'organization_id' => $organization->id,
                'accounting_period_id' => $period->id,
            ],
            [
                'id' => (string) Str::uuid(),
                'status' => 'in_progress',
                'notes' => "Month-end close for {$period->name}",
            ]
        );

        if ($cycle->tasks()->count() === 0) {
            $defaultTasks = [
                [
                    'task_key' => 'cash_reconciliation',
                    'title' => 'Reconcile all bank & cash accounts',
                    'description' => 'Match and clear all imported bank statement lines against ledger accounts.',
                    'category' => 'cash_banking',
                    'sort_order' => 1,
                ],
                [
                    'task_key' => 'depreciation_entries',
                    'title' => 'Post monthly asset depreciation entries',
                    'description' => 'Run straight-line depreciation routine across all registered fixed assets.',
                    'category' => 'assets',
                    'sort_order' => 2,
                ],
                [
                    'task_key' => 'prepaids_accruals',
                    'title' => 'Review and amortize prepaids & accruals',
                    'description' => 'Amortize prepaid rent/insurance and accrue unbilled liabilities.',
                    'category' => 'gl_review',
                    'sort_order' => 3,
                ],
                [
                    'task_key' => 'revenue_recognition',
                    'title' => 'Review revenue recognition schedule',
                    'description' => 'Ensure all billed contracts and deliverable milestones are recognized.',
                    'category' => 'receivables',
                    'sort_order' => 4,
                ],
                [
                    'task_key' => 'intercompany_eliminations',
                    'title' => 'Post intercompany eliminations',
                    'description' => 'Eliminate subsidiary receivables and payables for consolidated view.',
                    'category' => 'gl_review',
                    'sort_order' => 5,
                ],
                [
                    'task_key' => 'flux_analysis',
                    'title' => 'Run period-over-period flux analysis',
                    'description' => 'Analyze account balance fluctuations > 10% versus prior period.',
                    'category' => 'gl_review',
                    'sort_order' => 6,
                ],
                [
                    'task_key' => 'missing_documents',
                    'title' => 'Audit missing source documents',
                    'description' => 'Verify receipts and OCR source attachments on all transactions.',
                    'category' => 'compliance',
                    'sort_order' => 7,
                ],
                [
                    'task_key' => 'period_lock',
                    'title' => 'Review and lock accounting period',
                    'description' => 'Perform final audit sign-off and permanently freeze period from further postings.',
                    'category' => 'compliance',
                    'sort_order' => 8,
                ],
            ];

            foreach ($defaultTasks as $taskData) {
                CloseTask::withoutGlobalScopes()->create(array_merge($taskData, [
                    'id' => (string) Str::uuid(),
                    'organization_id' => $organization->id,
                    'close_cycle_id' => $cycle->id,
                    'is_completed' => false,
                ]));
            }

            $cycle->recalculateProgress();
        }

        return $cycle->load(['tasks', 'period']);
    }

    /**
     * Toggle or update completion status for a close task.
     */
    public function toggleTask(CloseTask $task, User $user, ?bool $completed = null): CloseTask
    {
        $newStatus = $completed ?? (! $task->is_completed);

        $task->update([
            'is_completed' => $newStatus,
            'completed_at' => $newStatus ? now() : null,
            'completed_by' => $newStatus ? $user->id : null,
        ]);

        $cycle = $task->cycle;
        $cycle->recalculateProgress();

        if (class_exists(AuditService::class)) {
            $auditService = $this->auditService ?? app(AuditService::class);
            $auditService->log(
                $task->organization_id,
                $user,
                'close_task:updated',
                $task,
                ['is_completed' => ! $newStatus],
                ['is_completed' => $newStatus, 'task_key' => $task->task_key]
            );
        }

        return $task->fresh(['cycle']);
    }

    /**
     * Register a new fixed asset for depreciation tracking.
     */
    public function createFixedAsset(Organization $organization, array $data): FixedAsset
    {
        $cost = (float) $data['purchase_cost'];
        $salvage = (float) ($data['salvage_value'] ?? 0.0);
        $lifeMonths = (int) ($data['useful_life_months'] ?? 60);

        if ($lifeMonths <= 0) {
            throw new InvalidArgumentException("Useful life in months must be greater than zero.");
        }

        $monthlyDepreciation = round(($cost - $salvage) / $lifeMonths, 4);

        return FixedAsset::create([
            'organization_id' => $organization->id,
            'asset_account_id' => $data['asset_account_id'],
            'accumulated_depreciation_account_id' => $data['accumulated_depreciation_account_id'],
            'depreciation_expense_account_id' => $data['depreciation_expense_account_id'],
            'asset_number' => $data['asset_number'] ?? ('FA-' . strtoupper(Str::random(6))),
            'name' => $data['name'],
            'purchase_date' => $data['purchase_date'],
            'purchase_cost' => $cost,
            'salvage_value' => $salvage,
            'useful_life_months' => $lifeMonths,
            'monthly_depreciation' => $monthlyDepreciation,
            'status' => 'active',
        ]);
    }

    /**
     * Run monthly straight-line depreciation routine for all active assets in the period.
     */
    public function runDepreciation(Organization $organization, AccountingPeriod $period, User $user): array
    {
        $assets = FixedAsset::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->where('status', 'active')
            ->get();

        if ($assets->isEmpty()) {
            return [
                'depreciation_posted' => false,
                'message' => 'No active fixed assets found for depreciation routine.',
                'total_amount' => 0.00,
                'assets_count' => 0,
            ];
        }

        // Group by depreciation expense and accumulated depreciation accounts
        $journalLines = [];
        $totalDepreciation = 0.0;

        foreach ($assets as $asset) {
            $amt = (float) $asset->monthly_depreciation;
            if ($amt <= 0) continue;

            $totalDepreciation += $amt;

            // 1. Debit Depreciation Expense
            $journalLines[] = [
                'account_id' => $asset->depreciation_expense_account_id,
                'description' => "Depreciation: {$asset->name} ({$asset->asset_number})",
                'debit' => $amt,
                'credit' => 0.0000,
            ];

            // 2. Credit Accumulated Depreciation
            $journalLines[] = [
                'account_id' => $asset->accumulated_depreciation_account_id,
                'description' => "Accum. Depr: {$asset->name} ({$asset->asset_number})",
                'debit' => 0.0000,
                'credit' => $amt,
            ];
        }

        if (empty($journalLines)) {
            return [
                'depreciation_posted' => false,
                'message' => 'Depreciation amounts sum to 0. No journal entry required.',
                'total_amount' => 0.00,
                'assets_count' => $assets->count(),
            ];
        }

        return DB::transaction(function () use ($organization, $period, $assets, $journalLines, $totalDepreciation, $user) {
            // Post balanced GL Journal Entry
            $draft = $this->postingEngine->createDraft($organization, [
                'entry_date' => $period->end_date->toDateString(),
                'accounting_period_id' => $period->id,
                'source_type' => 'depreciation',
                'description' => "Monthly Fixed Asset Depreciation - {$period->name}",
                'currency' => $organization->base_currency ?? 'PKR',
                'lines' => $journalLines,
            ], $user);

            $postedJournal = $this->postingEngine->postEntry($draft, $user);

            // Update assets
            foreach ($assets as $asset) {
                $asset->update(['last_depreciated_date' => $period->end_date->toDateString()]);
            }

            // Auto-mark checklist task complete
            $cycle = $this->getOrCreateCloseCycle($organization, $period);
            $task = $cycle->tasks()->where('task_key', 'depreciation_entries')->first();
            if ($task && ! $task->is_completed) {
                $this->toggleTask($task, $user, true);
            }

            return [
                'depreciation_posted' => true,
                'journal_entry_id' => $postedJournal->id,
                'entry_number' => $postedJournal->entry_number,
                'total_amount' => $totalDepreciation,
                'assets_count' => $assets->count(),
                'period' => $period->name,
            ];
        });
    }

    /**
     * Dispose of a fixed asset, recognizing gain/loss and clearing historical cost and accumulated depreciation.
     */
    public function disposeFixedAsset(Organization $organization, FixedAsset $asset, array $data, User $user): array
    {
        if ($asset->status === 'disposed') {
            throw new \InvalidArgumentException("Fixed asset '{$asset->name}' is already disposed.");
        }

        $disposalDate = $data['disposal_date'];
        $proceeds = round((float) ($data['proceeds'] ?? 0.0), 2);
        $cost = round((float) $asset->purchase_cost, 2);

        // Calculate accumulated depreciation up to disposal
        $accumulatedDepr = isset($data['accumulated_depreciation'])
            ? round((float) $data['accumulated_depreciation'], 2)
            : min($cost, round((float) $asset->monthly_depreciation * max(0, \Carbon\Carbon::parse($asset->purchase_date)->diffInMonths(\Carbon\Carbon::parse($disposalDate))), 2));

        $netBookValue = round($cost - $accumulatedDepr, 2);
        $gainOrLoss = round($proceeds - $netBookValue, 2);

        $period = AccountingPeriod::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->where('id', $data['accounting_period_id'])
            ->firstOrFail();

        return DB::transaction(function () use ($organization, $asset, $data, $user, $period, $proceeds, $cost, $accumulatedDepr, $netBookValue, $gainOrLoss, $disposalDate) {
            $lines = [];

            // 1. Proceeds received in cash/bank (if any)
            if ($proceeds > 0) {
                $lines[] = [
                    'account_id' => $data['proceeds_account_id'],
                    'description' => "Disposal proceeds: {$asset->name} ({$asset->asset_number})",
                    'debit' => $proceeds,
                    'credit' => 0.0000,
                ];
            }

            // 2. Derecognize Accumulated Depreciation (Debit contra-asset to clear)
            if ($accumulatedDepr > 0) {
                $lines[] = [
                    'account_id' => $asset->accumulated_depreciation_account_id,
                    'description' => "Accum. Depr write-off: {$asset->name} ({$asset->asset_number})",
                    'debit' => $accumulatedDepr,
                    'credit' => 0.0000,
                ];
            }

            // 3. Derecognize Asset Cost (Credit asset account to clear original cost)
            $lines[] = [
                'account_id' => $asset->asset_account_id,
                'description' => "Asset derecognition: {$asset->name} ({$asset->asset_number})",
                'debit' => 0.0000,
                'credit' => $cost,
            ];

            // 4. Gain or Loss on Disposal
            if ($gainOrLoss < 0) {
                // Loss on disposal -> Debit Expense
                $lines[] = [
                    'account_id' => $data['gain_loss_account_id'],
                    'description' => "Loss on disposal: {$asset->name} ({$asset->asset_number})",
                    'debit' => abs($gainOrLoss),
                    'credit' => 0.0000,
                ];
            } elseif ($gainOrLoss > 0) {
                // Gain on disposal -> Credit Income
                $lines[] = [
                    'account_id' => $data['gain_loss_account_id'],
                    'description' => "Gain on disposal: {$asset->name} ({$asset->asset_number})",
                    'debit' => 0.0000,
                    'credit' => $gainOrLoss,
                ];
            }

            // Create and post balanced journal
            $draft = $this->postingEngine->createDraft($organization, [
                'entry_date' => $disposalDate,
                'accounting_period_id' => $period->id,
                'source_type' => 'asset_disposal',
                'description' => "Fixed Asset Disposal - {$asset->name} ({$asset->asset_number})",
                'currency' => $organization->base_currency ?? 'PKR',
                'lines' => $lines,
            ], $user);

            $postedJournal = $this->postingEngine->postEntry($draft, $user);

            // Update asset status
            $asset->update([
                'status' => 'disposed',
                'disposal_date' => $disposalDate,
                'disposal_proceeds' => $proceeds,
                'gain_loss_amount' => $gainOrLoss,
            ]);

            return [
                'disposed' => true,
                'asset_id' => $asset->id,
                'journal_entry_id' => $postedJournal->id,
                'entry_number' => $postedJournal->entry_number,
                'cost' => $cost,
                'accumulated_depreciation' => $accumulatedDepr,
                'net_book_value' => $netBookValue,
                'proceeds' => $proceeds,
                'gain_or_loss' => $gainOrLoss,
            ];
        });
    }

    /**
     * Impair a fixed asset per IAS 36, recognizing impairment loss expense.
     */
    public function impairFixedAsset(Organization $organization, FixedAsset $asset, array $data, User $user): array
    {
        if ($asset->status === 'disposed') {
            throw new \InvalidArgumentException("Cannot impair an already disposed asset.");
        }

        $impairmentLoss = round((float) $data['impairment_loss'], 2);
        if ($impairmentLoss <= 0) {
            throw new \InvalidArgumentException("Impairment loss must be greater than zero.");
        }

        $impairmentDate = $data['impairment_date'];
        $period = AccountingPeriod::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->where('id', $data['accounting_period_id'])
            ->firstOrFail();

        return DB::transaction(function () use ($organization, $asset, $data, $user, $period, $impairmentLoss, $impairmentDate) {
            $lines = [
                // 1. Debit Impairment Loss Expense
                [
                    'account_id' => $data['impairment_loss_account_id'],
                    'description' => "Impairment loss: {$asset->name} ({$asset->asset_number})",
                    'debit' => $impairmentLoss,
                    'credit' => 0.0000,
                ],
                // 2. Credit Accumulated Depreciation / Impairment (Contra-asset)
                [
                    'account_id' => $asset->accumulated_depreciation_account_id,
                    'description' => "Accumulated impairment: {$asset->name} ({$asset->asset_number})",
                    'debit' => 0.0000,
                    'credit' => $impairmentLoss,
                ],
            ];

            $draft = $this->postingEngine->createDraft($organization, [
                'entry_date' => $impairmentDate,
                'accounting_period_id' => $period->id,
                'source_type' => 'asset_impairment',
                'description' => "Fixed Asset Impairment - {$asset->name} ({$asset->asset_number})",
                'currency' => $organization->base_currency ?? 'PKR',
                'lines' => $lines,
            ], $user);

            $postedJournal = $this->postingEngine->postEntry($draft, $user);

            $asset->update([
                'status' => 'impaired',
                'impairment_date' => $impairmentDate,
                'impairment_loss' => $impairmentLoss,
                'impairment_reason' => $data['reason'] ?? 'Carrying amount exceeded recoverable amount',
            ]);

            return [
                'impaired' => true,
                'asset_id' => $asset->id,
                'journal_entry_id' => $postedJournal->id,
                'entry_number' => $postedJournal->entry_number,
                'impairment_loss' => $impairmentLoss,
                'reason' => $asset->impairment_reason,
            ];
        });
    }


    /**
     * Run period-over-period balance flux analysis with variance commentary.
     */
    public function runFluxAnalysis(Organization $organization, AccountingPeriod $currentPeriod, ?AccountingPeriod $priorPeriod = null): array
    {
        // Resolve prior period if not provided
        if (! $priorPeriod) {
            $priorPeriod = AccountingPeriod::withoutGlobalScopes()
                ->where('organization_id', $organization->id)
                ->where('start_date', '<', $currentPeriod->start_date->toDateString())
                ->orderBy('start_date', 'desc')
                ->first();
        }

        $accounts = Account::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->where('is_active', true)
            ->orderBy('code')
            ->get();

        $fluxItems = [];
        $significantCount = 0;

        foreach ($accounts as $account) {
            // Calculate Current Period Net Activity
            $currentNet = (float) JournalLine::withoutGlobalScopes()
                ->where('organization_id', $organization->id)
                ->where('account_id', $account->id)
                ->whereHas('journalEntry', function ($q) use ($currentPeriod) {
                    $q->where('status', 'posted')
                      ->where('accounting_period_id', $currentPeriod->id);
                })
                ->selectRaw('COALESCE(SUM(debit - credit), 0) as net')
                ->value('net');

            // Calculate Prior Period Net Activity
            $priorNet = 0.0;
            if ($priorPeriod) {
                $priorNet = (float) JournalLine::withoutGlobalScopes()
                    ->where('organization_id', $organization->id)
                    ->where('account_id', $account->id)
                    ->whereHas('journalEntry', function ($q) use ($priorPeriod) {
                        $q->where('status', 'posted')
                          ->where('accounting_period_id', $priorPeriod->id);
                    })
                    ->selectRaw('COALESCE(SUM(debit - credit), 0) as net')
                    ->value('net');
            }

            // Normal debit/credit orientation adjustment
            if (in_array($account->classification, ['liability', 'equity', 'revenue'])) {
                $currentBalance = -$currentNet;
                $priorBalance = -$priorNet;
            } else {
                $currentBalance = $currentNet;
                $priorBalance = $priorNet;
            }

            $dollarChange = round($currentBalance - $priorBalance, 2);
            $percentChange = $priorBalance != 0 
                ? round(($dollarChange / abs($priorBalance)) * 100, 2)
                : ($currentBalance != 0 ? 100.00 : 0.00);

            $isSignificant = abs($dollarChange) >= 5000 || abs($percentChange) >= 15.0;
            if ($isSignificant && ($currentBalance != 0 || $priorBalance != 0)) {
                $significantCount++;
            }

            $commentary = null;
            if ($isSignificant) {
                if ($dollarChange > 0) {
                    $commentary = "Favorable/expansionary surge of {$percentChange}% observed in {$account->name}.";
                } else {
                    $commentary = "Reduction of " . abs($percentChange) . "% detected compared to prior period activity.";
                }
            }

            $fluxItems[] = [
                'account_id' => $account->id,
                'account_code' => $account->code,
                'account_name' => $account->name,
                'classification' => $account->classification,
                'prior_balance' => $priorBalance,
                'current_balance' => $currentBalance,
                'dollar_change' => $dollarChange,
                'percent_change' => $percentChange,
                'is_significant' => $isSignificant,
                'commentary' => $commentary,
            ];
        }

        return [
            'organization_id' => $organization->id,
            'current_period' => [
                'id' => $currentPeriod->id,
                'name' => $currentPeriod->name,
                'start_date' => $currentPeriod->start_date->toDateString(),
                'end_date' => $currentPeriod->end_date->toDateString(),
            ],
            'prior_period' => $priorPeriod ? [
                'id' => $priorPeriod->id,
                'name' => $priorPeriod->name,
                'start_date' => $priorPeriod->start_date->toDateString(),
                'end_date' => $priorPeriod->end_date->toDateString(),
            ] : null,
            'total_accounts_reviewed' => count($fluxItems),
            'significant_shifts_count' => $significantCount,
            'items' => $fluxItems,
        ];
    }

    /**
     * Inspect pre-close readiness to ensure all subsidiary ledgers and checklist tasks are cleared.
     */
    public function verifyCloseReadiness(Organization $organization, AccountingPeriod $period): array
    {
        $startDate = $period->start_date->toDateString();
        $endDate = $period->end_date->toDateString();

        $unreconciledTxCount = BankTransaction::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->whereBetween('transaction_date', [$startDate, $endDate])
            ->where('reconciliation_status', '!=', 'reconciled')
            ->count();

        $draftInvoicesCount = SalesInvoice::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->whereBetween('issue_date', [$startDate, $endDate])
            ->whereIn('status', ['draft', 'pending_approval'])
            ->count();

        $draftBillsCount = PurchaseBill::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->whereBetween('bill_date', [$startDate, $endDate])
            ->whereIn('status', ['draft', 'pending_approval'])
            ->count();

        $cycle = $this->getOrCreateCloseCycle($organization, $period);
        $incompleteTasksCount = $cycle->tasks()->where('is_completed', false)->count();

        $blockers = [];
        if ($unreconciledTxCount > 0) {
            $blockers[] = "{$unreconciledTxCount} bank transactions remain unreconciled in {$period->name}.";
        }
        if ($draftInvoicesCount > 0) {
            $blockers[] = "{$draftInvoicesCount} sales invoices are still in draft or pending approval.";
        }
        if ($draftBillsCount > 0) {
            $blockers[] = "{$draftBillsCount} purchase bills are still in draft or pending approval.";
        }
        if ($incompleteTasksCount > 0) {
            $blockers[] = "{$incompleteTasksCount} checklist tasks in the close cycle are not yet marked complete.";
        }

        $canClose = empty($blockers);

        return [
            'period_id' => $period->id,
            'period_name' => $period->name,
            'can_close' => $canClose,
            'readiness_score' => $canClose ? 100 : max(0, 100 - (count($blockers) * 25)),
            'unreconciled_transactions' => $unreconciledTxCount,
            'draft_invoices' => $draftInvoicesCount,
            'draft_bills' => $draftBillsCount,
            'incomplete_checklist_tasks' => $incompleteTasksCount,
            'blockers' => $blockers,
        ];
    }
}
