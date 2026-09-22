<?php

namespace App\Domain\Accounting\Period\Services;

use App\Domain\Accounting\Period\Models\AccountingPeriod;
use App\Domain\Accounting\Period\Models\FiscalYear;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PeriodManager
{
    /**
     * Generate a fiscal year and its 12 monthly accounting periods for an organization.
     */
    public function generateFiscalYear(Organization $organization, int $startYear): FiscalYear
    {
        $startMonth = (int) ($organization->fiscal_year_start_month ?? 7);

        if ($startMonth === 7) {
            // Standard Pakistan Fiscal Year (July 1 to June 30)
            $startDate = Carbon::createFromDate($startYear, 7, 1)->startOfDay();
            $endDate = Carbon::createFromDate($startYear + 1, 6, 30)->endOfDay();
            $name = sprintf('FY %d-%d', $startYear, $startYear + 1);
        } elseif ($startMonth === 1) {
            // Calendar Fiscal Year (Jan 1 to Dec 31)
            $startDate = Carbon::createFromDate($startYear, 1, 1)->startOfDay();
            $endDate = Carbon::createFromDate($startYear, 12, 31)->endOfDay();
            $name = sprintf('FY %d', $startYear);
        } else {
            // Custom Start Month
            $startDate = Carbon::createFromDate($startYear, $startMonth, 1)->startOfDay();
            $endDate = (clone $startDate)->addYear()->subDay()->endOfDay();
            $name = sprintf('FY %d (%s-%s)', $startYear, $startDate->format('M'), $endDate->format('M'));
        }

        return DB::transaction(function () use ($organization, $name, $startDate, $endDate) {
            $fiscalYear = FiscalYear::withoutGlobalScopes()->updateOrCreate(
                [
                    'organization_id' => $organization->id,
                    'name' => $name,
                ],
                [
                    'start_date' => $startDate->toDateString(),
                    'end_date' => $endDate->toDateString(),
                    'is_closed' => false,
                ]
            );

            // Generate 12 monthly periods
            for ($monthOffset = 0; $monthOffset < 12; $monthOffset++) {
                $periodStart = (clone $startDate)->addMonths($monthOffset)->startOfMonth();
                $periodEnd = (clone $periodStart)->endOfMonth();
                $periodNumber = $monthOffset + 1;

                AccountingPeriod::withoutGlobalScopes()->updateOrCreate(
                    [
                        'organization_id' => $organization->id,
                        'fiscal_year_id' => $fiscalYear->id,
                        'period_number' => $periodNumber,
                    ],
                    [
                        'name' => $periodStart->format('F Y'),
                        'start_date' => $periodStart->toDateString(),
                        'end_date' => $periodEnd->toDateString(),
                        'status' => 'open',
                    ]
                );
            }

            return $fiscalYear->load('periods');
        });
    }

    /**
     * Locate an active open accounting period for the given transaction date.
     */
    public function getOpenPeriodForDate(Organization $organization, Carbon|string $date): ?AccountingPeriod
    {
        $dateStr = $date instanceof Carbon ? $date->toDateString() : $date;

        return AccountingPeriod::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->where('start_date', '<=', $dateStr)
            ->where('end_date', '>=', $dateStr)
            ->where('status', 'open')
            ->first();
    }

    /**
     * Close an accounting period.
     */
    public function closePeriod(AccountingPeriod $period, User $user): AccountingPeriod
    {
        if ($period->isClosed()) {
            return $period;
        }

        $period->update([
            'status' => 'closed',
            'closed_at' => now(),
            'closed_by' => $user->id,
        ]);

        return $period;
    }

    /**
     * Reopen a closed or locked period with a mandatory audit reason.
     */
    public function reopenPeriod(AccountingPeriod $period, User $user, string $reason): AccountingPeriod
    {
        if (empty(trim($reason))) {
            throw new InvalidArgumentException('A valid reason is required to reopen an accounting period.');
        }

        $period->update([
            'status' => 'open',
            'reopened_at' => now(),
            'reopened_by' => $user->id,
            'reopen_reason' => trim($reason),
        ]);

        return $period;
    }

    /**
     * Lock an accounting period (permanent audit freeze).
     */
    public function lockPeriod(AccountingPeriod $period, User $user): AccountingPeriod
    {
        $period->update([
            'status' => 'locked',
            'closed_at' => $period->closed_at ?? now(),
            'closed_by' => $period->closed_by ?? $user->id,
        ]);

        return $period;
    }
}
