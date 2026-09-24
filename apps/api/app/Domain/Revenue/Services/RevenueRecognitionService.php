<?php

namespace App\Domain\Revenue\Services;

use App\Domain\Accounting\ChartOfAccounts\Models\Account;
use App\Domain\Accounting\Period\Models\AccountingPeriod;
use App\Domain\Accounting\Posting\Services\PostingEngine;
use App\Domain\Audit\Services\AuditService;
use App\Domain\Organization\Models\Organization;
use App\Domain\Revenue\Models\RevenueContract;
use App\Domain\Revenue\Models\RevenueSchedule;
use App\Domain\Sales\Models\Customer;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class RevenueRecognitionService
{
    public function __construct(
        private readonly PostingEngine $postingEngine,
        private readonly ?AuditService $auditService = null
    ) {
    }

    /**
     * Create a new Revenue Contract and automatically generate recognition schedules.
     */
    public function createContract(Organization $organization, array $data, ?User $user = null): RevenueContract
    {
        return DB::transaction(function () use ($organization, $data, $user) {
            $startDate = Carbon::parse($data['start_date']);
            $endDate = Carbon::parse($data['end_date']);

            if ($endDate->lt($startDate)) {
                throw new InvalidArgumentException("End date cannot be prior to start date.");
            }

            $totalValue = (float) $data['total_contract_value'];
            if ($totalValue <= 0) {
                throw new InvalidArgumentException("Total contract value must be strictly positive.");
            }

            // Generate unique contract number if not provided
            $contractNumber = $data['contract_number'] ?? 'REV-' . date('Y') . '-' . strtoupper(Str::random(6));

            // Default or resolve deferred revenue account (2070 or liability)
            $deferredAccountId = $data['deferred_revenue_account_id'] ?? null;
            if (! $deferredAccountId) {
                $deferredAcc = Account::withoutGlobalScopes()
                    ->where('organization_id', $organization->id)
                    ->where(function ($q) {
                        $q->where('code', '2070')
                          ->orWhere('name', 'ILIKE', '%deferred%');
                    })
                    ->first();

                if (! $deferredAcc) {
                    $deferredAcc = Account::withoutGlobalScopes()
                        ->where('organization_id', $organization->id)
                        ->where('classification', 'liability')
                        ->firstOrFail();
                }
                $deferredAccountId = $deferredAcc->id;
            }

            // Default or resolve earned revenue account (4010 or 4020)
            $revenueAccountId = $data['revenue_account_id'] ?? null;
            if (! $revenueAccountId) {
                $revAcc = Account::withoutGlobalScopes()
                    ->where('organization_id', $organization->id)
                    ->where('code', '4020')
                    ->first();

                if (! $revAcc) {
                    $revAcc = Account::withoutGlobalScopes()
                        ->where('organization_id', $organization->id)
                        ->where('classification', 'revenue')
                        ->firstOrFail();
                }
                $revenueAccountId = $revAcc->id;
            }

            $contract = RevenueContract::withoutGlobalScopes()->create([
                'organization_id' => $organization->id,
                'customer_id' => $data['customer_id'],
                'contract_number' => $contractNumber,
                'title' => $data['title'],
                'start_date' => $startDate->toDateString(),
                'end_date' => $endDate->toDateString(),
                'total_contract_value' => $totalValue,
                'currency' => $data['currency'] ?? $organization->base_currency ?? 'PKR',
                'recognition_method' => $data['recognition_method'] ?? 'straight_line',
                'status' => 'active',
                'deferred_revenue_account_id' => $deferredAccountId,
                'revenue_account_id' => $revenueAccountId,
                'notes' => $data['notes'] ?? null,
                'created_by' => $user?->id,
            ]);

            // Automatically build recognition schedules
            $this->generateSchedules($contract);

            if ($this->auditService) {
                $this->auditService->log(
                    organizationId: $organization->id,
                    user: $user,
                    action: 'revenue_contract:created',
                    auditable: $contract,
                    oldValues: [],
                    newValues: [
                        'contract_number' => $contract->contract_number,
                        'total_value' => (string) $contract->total_contract_value,
                        'method' => $contract->recognition_method,
                    ]
                );
            }

            return $contract->load(['schedules', 'customer', 'deferredRevenueAccount', 'revenueAccount']);
        });
    }

    /**
     * Generate straight-line monthly amortization schedules across the contract period.
     */
    public function generateSchedules(RevenueContract $contract): void
    {
        $startDate = Carbon::parse($contract->start_date)->startOfMonth();
        $endDate = Carbon::parse($contract->end_date)->startOfMonth();

        // Calculate exact calendar months (minimum 1)
        $months = max(1, (($endDate->year - $startDate->year) * 12) + ($endDate->month - $startDate->month) + 1);
        $monthlyAmount = round((float) $contract->total_contract_value / $months, 4);

        $cumulative = 0.0;
        $currentDate = Carbon::parse($contract->start_date)->endOfMonth();

        for ($i = 1; $i <= $months; $i++) {
            // Adjust last month for rounding differences to match exact total contract value
            if ($i === $months) {
                $amount = round((float) $contract->total_contract_value - $cumulative, 4);
            } else {
                $amount = $monthlyAmount;
            }

            $cumulative += $amount;

            // Find matching accounting period if one exists
            $period = AccountingPeriod::withoutGlobalScopes()
                ->where('organization_id', $contract->organization_id)
                ->where('start_date', '<=', $currentDate->toDateString())
                ->where('end_date', '>=', $currentDate->toDateString())
                ->first();

            RevenueSchedule::withoutGlobalScopes()->create([
                'organization_id' => $contract->organization_id,
                'revenue_contract_id' => $contract->id,
                'accounting_period_id' => $period?->id,
                'schedule_date' => $currentDate->toDateString(),
                'amount' => $amount,
                'cumulative_recognized' => 0.0000,
                'status' => 'pending',
            ]);

            $currentDate = $currentDate->copy()->addMonthNoOverflow()->endOfMonth();
        }
    }

    /**
     * Recognize and post a revenue schedule to the General Ledger.
     * Invariant: Debit Deferred Revenue (2070), Credit Earned Revenue (4020).
     */
    public function recognizeSchedule(RevenueSchedule $schedule, User $user): RevenueSchedule
    {
        if ($schedule->status === 'posted') {
            throw new InvalidArgumentException("This revenue recognition schedule has already been posted.");
        }

        $contract = $schedule->contract;
        $organization = Organization::findOrFail($schedule->organization_id);

        return DB::transaction(function () use ($organization, $contract, $schedule, $user) {
            $amount = (float) $schedule->amount;

            // Build double-entry lines:
            // 1. Debit Deferred Revenue (reduces liability)
            // 2. Credit Revenue (increases recognized income)
            $journalLines = [
                [
                    'account_id' => $contract->deferred_revenue_account_id,
                    'description' => "Amortize Deferred Revenue: Contract {$contract->contract_number}",
                    'debit' => $amount,
                    'credit' => 0.0000,
                ],
                [
                    'account_id' => $contract->revenue_account_id,
                    'description' => "Earned Revenue: Contract {$contract->contract_number} ({$contract->title})",
                    'debit' => 0.0000,
                    'credit' => $amount,
                ],
            ];

            // Post balanced journal entry via PostingEngine
            $draft = $this->postingEngine->createDraft($organization, [
                'entry_date' => $schedule->schedule_date->toDateString(),
                'accounting_period_id' => $schedule->accounting_period_id,
                'source_type' => 'revenue_recognition',
                'source_id' => $schedule->id,
                'description' => "RevRec Amortization: Contract #{$contract->contract_number} ({$contract->title})",
                'currency' => $contract->currency ?? $organization->base_currency ?? 'PKR',
                'lines' => $journalLines,
            ], $user);

            $postedJournal = $this->postingEngine->postEntry($draft, $user);

            // Update schedule record
            $cumulative = (float) $contract->schedules()
                ->where('status', 'posted')
                ->sum('amount') + $amount;

            $schedule->update([
                'status' => 'posted',
                'journal_entry_id' => $postedJournal->id,
                'cumulative_recognized' => $cumulative,
                'recognized_at' => now(),
                'recognized_by' => $user->id,
            ]);

            // Check if all schedules are posted; if so, mark contract completed
            $unpostedCount = $contract->schedules()->where('status', '!=', 'posted')->count();
            if ($unpostedCount === 0) {
                $contract->update(['status' => 'completed']);
            }

            if ($this->auditService) {
                $this->auditService->log(
                    organizationId: $organization->id,
                    user: $user,
                    action: 'revenue_schedule:recognized',
                    auditable: $schedule,
                    oldValues: ['status' => 'pending'],
                    newValues: [
                        'status' => 'posted',
                        'amount' => (string) $amount,
                        'journal_entry_id' => $postedJournal->id,
                    ]
                );
            }

            return $schedule->fresh(['contract', 'journalEntry']);
        });
    }

    /**
     * Recompute schedules when a contract value or end date is amended.
     */
    public function amendContract(RevenueContract $contract, float $newTotalValue, ?string $newEndDate, User $user): RevenueContract
    {
        return DB::transaction(function () use ($contract, $newTotalValue, $newEndDate, $user) {
            $alreadyRecognized = (float) $contract->schedules()->where('status', 'posted')->sum('amount');

            if ($newTotalValue < $alreadyRecognized) {
                throw new InvalidArgumentException(
                    "New contract value ({$newTotalValue}) cannot be lower than already recognized revenue ({$alreadyRecognized})."
                );
            }

            $remainingValue = $newTotalValue - $alreadyRecognized;

            // Delete pending schedules
            $contract->schedules()->where('status', 'pending')->delete();

            $contract->update([
                'total_contract_value' => $newTotalValue,
                'end_date' => $newEndDate ?? $contract->end_date,
            ]);

            if ($remainingValue > 0) {
                $lastPosted = $contract->schedules()->where('status', 'posted')->orderByDesc('schedule_date')->first();
                $cur = $lastPosted
                    ? Carbon::parse($lastPosted->schedule_date)->addMonthNoOverflow()->endOfMonth()
                    : Carbon::parse($contract->start_date)->endOfMonth();

                $startDate = $cur->copy()->startOfMonth();
                $endDate = Carbon::parse($contract->end_date)->startOfMonth();
                $remainingMonths = max(1, (($endDate->year - $startDate->year) * 12) + ($endDate->month - $startDate->month) + 1);
                $monthlyAmount = round($remainingValue / $remainingMonths, 4);

                $cum = 0.0;

                for ($i = 1; $i <= $remainingMonths; $i++) {
                    $amt = ($i === $remainingMonths) ? round($remainingValue - $cum, 4) : $monthlyAmount;
                    $cum += $amt;

                    // Match period if exists
                    $period = AccountingPeriod::withoutGlobalScopes()
                        ->where('organization_id', $contract->organization_id)
                        ->where('start_date', '<=', $cur->toDateString())
                        ->where('end_date', '>=', $cur->toDateString())
                        ->first();

                    RevenueSchedule::withoutGlobalScopes()->create([
                        'organization_id' => $contract->organization_id,
                        'revenue_contract_id' => $contract->id,
                        'accounting_period_id' => $period?->id,
                        'schedule_date' => $cur->toDateString(),
                        'amount' => $amt,
                        'cumulative_recognized' => 0.0,
                        'status' => 'pending',
                    ]);

                    $cur = $cur->copy()->addMonthNoOverflow()->endOfMonth();
                }
            }

            return $contract->fresh(['schedules']);
        });
    }
}
