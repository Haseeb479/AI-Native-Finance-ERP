<?php

namespace App\Domain\Reporting\Services;

use App\Domain\Accounting\ChartOfAccounts\Models\Account;
use App\Domain\Accounting\Journal\Models\JournalLine;
use App\Domain\Organization\Models\Organization;
use App\Domain\Purchasing\Models\PurchaseBill;
use App\Domain\Sales\Models\SalesInvoice;
use Illuminate\Support\Facades\DB;

class ReportingService
{
    // ─────────────────────────────────────────────────────────────────────────
    // TRIAL BALANCE
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Trial Balance: all account net balances as of a given date.
     * Invariant: total_debit == total_credit (always for a double-entry system).
     */
    public function trialBalance(Organization $org, string $asOfDate): array
    {
        $rows = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'jl.journal_entry_id', '=', 'je.id')
            ->join('accounts as a', 'jl.account_id', '=', 'a.id')
            ->where('jl.organization_id', $org->id)
            ->where('je.status', 'posted')
            ->where('je.entry_date', '<=', $asOfDate)
            ->groupBy('a.id', 'a.code', 'a.name', 'a.classification', 'a.normal_balance')
            ->select([
                'a.id as account_id',
                'a.code',
                'a.name',
                'a.classification',
                'a.normal_balance',
                DB::raw('SUM(jl.debit) as total_debit'),
                DB::raw('SUM(jl.credit) as total_credit'),
            ])
            ->orderBy('a.code')
            ->get();

        $accounts = [];
        $grandDebit = 0;
        $grandCredit = 0;

        foreach ($rows as $row) {
            $debit  = (float) $row->total_debit;
            $credit = (float) $row->total_credit;
            $net    = $this->netBalance($debit, $credit, $row->normal_balance);

            $accounts[] = [
                'account_id'     => $row->account_id,
                'code'           => $row->code,
                'name'           => $row->name,
                'classification' => $row->classification,
                'normal_balance' => $row->normal_balance,
                'total_debit'    => round($debit, 2),
                'total_credit'   => round($credit, 2),
                'net_balance'    => round($net, 2),
            ];

            $grandDebit  += $debit;
            $grandCredit += $credit;
        }

        return [
            'as_of_date'      => $asOfDate,
            'accounts'        => $accounts,
            'totals'          => [
                'total_debit'  => round($grandDebit, 2),
                'total_credit' => round($grandCredit, 2),
            ],
            'is_balanced'     => abs($grandDebit - $grandCredit) < 0.01,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PROFIT & LOSS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Profit & Loss for a date range.
     * Revenue − Expenses = Net Profit (or Loss).
     */
    public function profitAndLoss(Organization $org, string $fromDate, string $toDate): array
    {
        $rows = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'jl.journal_entry_id', '=', 'je.id')
            ->join('accounts as a', 'jl.account_id', '=', 'a.id')
            ->whereIn('a.classification', ['revenue', 'expense'])
            ->where('jl.organization_id', $org->id)
            ->where('je.status', 'posted')
            ->whereBetween('je.entry_date', [$fromDate, $toDate])
            ->groupBy('a.id', 'a.code', 'a.name', 'a.classification', 'a.normal_balance', 'a.account_group_id')
            ->select([
                'a.id as account_id',
                'a.code',
                'a.name',
                'a.classification',
                'a.normal_balance',
                'a.account_group_id',
                DB::raw('SUM(jl.debit) as total_debit'),
                DB::raw('SUM(jl.credit) as total_credit'),
            ])
            ->orderBy('a.code')
            ->get();

        $revenue  = [];
        $cogs     = [];
        $expenses = [];

        $totalRevenue  = 0;
        $totalCogs     = 0;
        $totalExpenses = 0;

        // Load group slugs for COGS detection
        $cogsGroupIds = DB::table('account_groups')
            ->where('slug', 'cost-of-goods-sold')
            ->pluck('id')
            ->all();

        foreach ($rows as $row) {
            $net = $this->netBalance(
                (float) $row->total_debit,
                (float) $row->total_credit,
                $row->normal_balance
            );
            $line = [
                'account_id'     => $row->account_id,
                'code'           => $row->code,
                'name'           => $row->name,
                'amount'         => round($net, 2),
            ];

            if ($row->classification === 'revenue') {
                $revenue[] = $line;
                $totalRevenue += $net;
            } elseif ($row->classification === 'expense') {
                if (in_array($row->account_group_id, $cogsGroupIds)) {
                    $cogs[] = $line;
                    $totalCogs += $net;
                } else {
                    $expenses[] = $line;
                    $totalExpenses += $net;
                }
            }
        }

        $grossProfit    = $totalRevenue - $totalCogs;
        $operatingProfit = $grossProfit - $totalExpenses;

        return [
            'from_date'        => $fromDate,
            'to_date'          => $toDate,
            'revenue'          => [
                'accounts'     => $revenue,
                'total'        => round($totalRevenue, 2),
            ],
            'cost_of_goods_sold' => [
                'accounts'     => $cogs,
                'total'        => round($totalCogs, 2),
            ],
            'gross_profit'     => round($grossProfit, 2),
            'operating_expenses' => [
                'accounts'     => $expenses,
                'total'        => round($totalExpenses, 2),
            ],
            'net_profit'       => round($operatingProfit, 2),
            'is_profitable'    => $operatingProfit >= 0,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // BALANCE SHEET
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Balance Sheet as of a date.
     * Invariant: Total Assets == Total Liabilities + Total Equity.
     * Retained earnings = Net P&L from all posted history (inception to asOfDate).
     */
    public function balanceSheet(Organization $org, string $asOfDate): array
    {
        $rows = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'jl.journal_entry_id', '=', 'je.id')
            ->join('accounts as a', 'jl.account_id', '=', 'a.id')
            ->where('jl.organization_id', $org->id)
            ->where('je.status', 'posted')
            ->where('je.entry_date', '<=', $asOfDate)
            ->groupBy('a.id', 'a.code', 'a.name', 'a.classification', 'a.normal_balance')
            ->select([
                'a.id as account_id',
                'a.code',
                'a.name',
                'a.classification',
                'a.normal_balance',
                DB::raw('SUM(jl.debit) as total_debit'),
                DB::raw('SUM(jl.credit) as total_credit'),
            ])
            ->orderBy('a.code')
            ->get();

        $assets      = [];
        $liabilities = [];
        $equity      = [];

        $totalAssets      = 0;
        $totalLiabilities = 0;
        $totalEquity      = 0;
        $plNet            = 0; // retained earnings from income/expense accounts

        foreach ($rows as $row) {
            $net = $this->netBalance(
                (float) $row->total_debit,
                (float) $row->total_credit,
                $row->normal_balance
            );
            $line = [
                'account_id'     => $row->account_id,
                'code'           => $row->code,
                'name'           => $row->name,
                'balance'        => round($net, 2),
            ];

            switch ($row->classification) {
                case 'asset':
                    $assets[] = $line;
                    $totalAssets += $net;
                    break;
                case 'liability':
                    $liabilities[] = $line;
                    $totalLiabilities += $net;
                    break;
                case 'equity':
                    $equity[] = $line;
                    $totalEquity += $net;
                    break;
                case 'revenue':
                    // Retained earnings contribution: revenue increases equity
                    $plNet += $net;
                    break;
                case 'expense':
                    // Expenses reduce equity
                    $plNet -= $net;
                    break;
            }
        }

        // Net P&L flows into retained earnings on the balance sheet
        $totalEquityWithRetained = $totalEquity + $plNet;

        return [
            'as_of_date'         => $asOfDate,
            'assets'             => [
                'accounts'       => $assets,
                'total'          => round($totalAssets, 2),
            ],
            'liabilities'        => [
                'accounts'       => $liabilities,
                'total'          => round($totalLiabilities, 2),
            ],
            'equity'             => [
                'accounts'       => $equity,
                'retained_earnings' => round($plNet, 2),
                'total'          => round($totalEquityWithRetained, 2),
            ],
            'total_liabilities_and_equity' => round($totalLiabilities + $totalEquityWithRetained, 2),
            'is_balanced'        => abs($totalAssets - ($totalLiabilities + $totalEquityWithRetained)) < 0.01,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GENERAL LEDGER
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * General Ledger: line-by-line transaction history for each account,
     * with opening balance and running balance per line.
     */
    public function generalLedger(
        Organization $org,
        string $fromDate,
        string $toDate,
        ?string $accountId = null
    ): array {
        // Opening balance: all posted lines BEFORE fromDate
        $openingQuery = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'jl.journal_entry_id', '=', 'je.id')
            ->join('accounts as a', 'jl.account_id', '=', 'a.id')
            ->where('jl.organization_id', $org->id)
            ->where('je.status', 'posted')
            ->where('je.entry_date', '<', $fromDate)
            ->groupBy('a.id', 'a.code', 'a.name', 'a.classification', 'a.normal_balance')
            ->select([
                'a.id as account_id',
                'a.code',
                'a.name',
                'a.classification',
                'a.normal_balance',
                DB::raw('SUM(jl.debit) as debit'),
                DB::raw('SUM(jl.credit) as credit'),
            ]);

        // Period lines
        $periodQuery = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'jl.journal_entry_id', '=', 'je.id')
            ->join('accounts as a', 'jl.account_id', '=', 'a.id')
            ->where('jl.organization_id', $org->id)
            ->where('je.status', 'posted')
            ->whereBetween('je.entry_date', [$fromDate, $toDate])
            ->select([
                'a.id as account_id',
                'a.code',
                'a.name',
                'a.classification',
                'a.normal_balance',
                'je.entry_number',
                'je.entry_date',
                'je.description as entry_description',
                'jl.description as line_description',
                'jl.debit',
                'jl.credit',
            ])
            ->orderBy('a.code')
            ->orderBy('je.entry_date')
            ->orderBy('je.entry_number');

        if ($accountId) {
            $openingQuery->where('a.id', $accountId);
            $periodQuery->where('a.id', $accountId);
        }

        $openingBalances = $openingQuery->get()->keyBy('account_id');
        $periodLines     = $periodQuery->get();

        // Group period lines by account
        $byAccount = [];
        foreach ($periodLines as $line) {
            $byAccount[$line->account_id][] = $line;
        }

        // Also include accounts that have opening balance even if no period activity
        foreach ($openingBalances as $accId => $ob) {
            if (!isset($byAccount[$accId])) {
                $byAccount[$accId] = [];
            }
        }

        $ledger = [];
        foreach ($byAccount as $accId => $lines) {
            $ob = $openingBalances->get($accId);
            $normalBalance = $lines[0]->normal_balance ?? ($ob?->normal_balance ?? 'debit');

            $openingNet = $ob
                ? $this->netBalance((float) $ob->debit, (float) $ob->credit, $normalBalance)
                : 0.0;

            $runningBalance = $openingNet;
            $periodLines    = [];

            foreach ($lines as $line) {
                $debit  = (float) $line->debit;
                $credit = (float) $line->credit;
                $movement = $this->netBalance($debit, $credit, $normalBalance);
                $runningBalance += $movement;

                $periodLines[] = [
                    'entry_number'     => $line->entry_number,
                    'entry_date'       => $line->entry_date,
                    'description'      => $line->line_description ?: $line->entry_description,
                    'debit'            => round($debit, 2),
                    'credit'           => round($credit, 2),
                    'running_balance'  => round($runningBalance, 2),
                ];
            }

            $firstLine = $lines[0] ?? $ob;
            $ledger[] = [
                'account_id'      => $accId,
                'code'            => $firstLine->code ?? ($ob?->code ?? ''),
                'name'            => $firstLine->name ?? ($ob?->name ?? ''),
                'classification'  => $firstLine->classification ?? ($ob?->classification ?? ''),
                'opening_balance' => round($openingNet, 2),
                'closing_balance' => round($runningBalance, 2),
                'lines'           => $periodLines,
            ];
        }

        usort($ledger, fn($a, $b) => strcmp($a['code'], $b['code']));

        return [
            'from_date' => $fromDate,
            'to_date'   => $toDate,
            'accounts'  => $ledger,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // AR AGING
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * AR Aging: outstanding sales invoices bucketed by days overdue.
     */
    public function arAging(Organization $org, string $asOfDate): array
    {
        $invoices = SalesInvoice::withoutGlobalScopes()
            ->with('customer:id,name,email')
            ->where('organization_id', $org->id)
            ->whereIn('status', ['sent', 'partial'])
            ->where('total_amount', '>', 0)
            ->get();

        return $this->buildAgingReport($invoices, $asOfDate, 'customer', 'total_amount', 'amount_paid', 'due_date');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // AP AGING
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * AP Aging: outstanding purchase bills bucketed by days overdue.
     */
    public function apAging(Organization $org, string $asOfDate): array
    {
        $bills = PurchaseBill::withoutGlobalScopes()
            ->with('vendor:id,name,email')
            ->where('organization_id', $org->id)
            ->whereIn('status', ['received', 'partial'])
            ->where('net_payable', '>', 0)
            ->get();

        return $this->buildAgingReport($bills, $asOfDate, 'vendor', 'net_payable', 'amount_paid', 'due_date');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SHARED HELPERS
    // ─────────────────────────────────────────────────────────────────────────

    private function buildAgingReport(
        $records,
        string $asOfDate,
        string $partyRelation,
        string $totalField,
        string $paidField,
        string $dueDateField
    ): array {
        $asOf = \Carbon\Carbon::parse($asOfDate);

        $buckets = [
            'current'  => ['label' => 'Current (Not Yet Due)', 'total' => 0, 'items' => []],
            '1_30'     => ['label' => '1–30 Days',              'total' => 0, 'items' => []],
            '31_60'    => ['label' => '31–60 Days',             'total' => 0, 'items' => []],
            '61_90'    => ['label' => '61–90 Days',             'total' => 0, 'items' => []],
            'over_90'  => ['label' => 'Over 90 Days',           'total' => 0, 'items' => []],
        ];

        $grandTotal = 0;

        foreach ($records as $record) {
            $balance = max(0, (float) $record->$totalField - (float) $record->$paidField);
            if ($balance <= 0) continue;

            $dueDate = \Carbon\Carbon::parse($record->$dueDateField);
            $daysOverdue = (int) $dueDate->diffInDays($asOf, false); // negative = not yet due

            $party = $record->$partyRelation;
            $item = [
                'id'          => $record->id,
                'number'      => $record->invoice_number ?? $record->bill_number ?? '',
                'due_date'    => $record->$dueDateField,
                'days_overdue'=> max(0, $daysOverdue),
                'balance_due' => round($balance, 2),
                'party_id'    => $party?->id,
                'party_name'  => $party?->name ?? 'Unknown',
            ];

            $bucket = match (true) {
                $daysOverdue <= 0  => 'current',
                $daysOverdue <= 30 => '1_30',
                $daysOverdue <= 60 => '31_60',
                $daysOverdue <= 90 => '61_90',
                default            => 'over_90',
            };

            $buckets[$bucket]['items'][] = $item;
            $buckets[$bucket]['total'] += $balance;
            $grandTotal += $balance;
        }

        foreach ($buckets as &$bucket) {
            $bucket['total'] = round($bucket['total'], 2);
        }

        return [
            'as_of_date'  => $asOfDate,
            'grand_total' => round($grandTotal, 2),
            'buckets'     => $buckets,
        ];
    }

    /**
     * Compute net balance respecting normal_balance direction.
     * Debit-normal accounts: net = debit - credit
     * Credit-normal accounts: net = credit - debit
     */
    private function netBalance(float $debit, float $credit, string $normalBalance): float
    {
        return $normalBalance === 'debit'
            ? $debit - $credit
            : $credit - $debit;
    }
}
