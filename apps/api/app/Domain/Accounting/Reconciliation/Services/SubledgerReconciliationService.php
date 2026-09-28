<?php

namespace App\Domain\Accounting\Reconciliation\Services;

use App\Domain\Accounting\ChartOfAccounts\Models\Account;
use App\Domain\Accounting\Journal\Models\JournalEntry;
use App\Domain\Accounting\Journal\Models\JournalLine;
use App\Domain\Banking\Models\BankAccount;
use App\Domain\Organization\Models\Organization;
use App\Domain\Purchasing\Models\PurchaseBill;
use App\Domain\Sales\Models\SalesInvoice;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class SubledgerReconciliationService
{
    /**
     * Reconcile Accounts Receivable (AR Subledger Invoices vs GL Control Account 1100).
     */
    public function reconcileAccountsReceivable(Organization $organization, ?Carbon $asOfDate = null): array
    {
        $asOf = $asOfDate ?? now();
        $dateStr = $asOf->toDateString();

        // 1. Subledger Total: Sum of outstanding receivables from sales invoices
        $invoicesQuery = SalesInvoice::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->whereIn('status', ['posted', 'approved', 'partially_paid', 'sent'])
            ->where('issue_date', '<=', $dateStr);

        $subledgerInvoices = $invoicesQuery->get();
        $subledgerTotal = '0.0000';
        foreach ($subledgerInvoices as $inv) {
            $total = (string) ($inv->total_amount ?? '0.0000');
            $paid = (string) ($inv->amount_paid ?? '0.0000');
            $outstanding = bcsub($total, $paid, 4);
            $subledgerTotal = bcadd($subledgerTotal, $outstanding, 4);
        }

        // 2. GL Balance: Net debits to AR Control Account (1100 / 1030)
        $glBalance = '0.0000';
        $arAccount = Account::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->whereIn('code', ['1100', '1030'])
            ->first();

        $directPostingsCount = 0;
        if ($arAccount) {
            $glLines = JournalLine::withoutGlobalScopes()
                ->where('organization_id', $organization->id)
                ->where('account_id', $arAccount->id)
                ->whereHas('journalEntry', function ($q) use ($dateStr) {
                    $q->withoutGlobalScopes()
                        ->where('status', 'posted')
                        ->where('entry_date', '<=', $dateStr);
                })
                ->get();

            foreach ($glLines as $line) {
                $debit = (string) ($line->debit ?? '0.0000');
                $credit = (string) ($line->credit ?? '0.0000');
                $glBalance = bcadd($glBalance, bcsub($debit, $credit, 4), 4);
            }

            // Flag manual journal entries posted directly to control account (P1-25 audit)
            $directPostingsCount = JournalEntry::withoutGlobalScopes()
                ->where('organization_id', $organization->id)
                ->where('status', 'posted')
                ->where('source_type', 'manual')
                ->where('entry_date', '<=', $dateStr)
                ->whereHas('lines', function ($q) use ($arAccount) {
                    $q->withoutGlobalScopes()->where('account_id', $arAccount->id);
                })
                ->count();
        }

        $variance = bcsub($subledgerTotal, $glBalance, 4);
        $isReconciled = (bccomp($variance, '0.0000', 4) === 0);

        return [
            'subledger_type' => 'accounts_receivable',
            'control_account_code' => $arAccount?->code ?? '1100',
            'control_account_name' => $arAccount?->name ?? 'Accounts Receivable',
            'as_of_date' => $dateStr,
            'subledger_balance' => $subledgerTotal,
            'gl_balance' => $glBalance,
            'variance' => $variance,
            'status' => $isReconciled ? 'reconciled' : 'discrepancy',
            'open_subledger_items_count' => $subledgerInvoices->count(),
            'direct_manual_postings_count' => $directPostingsCount,
        ];
    }

    /**
     * Reconcile Accounts Payable (AP Subledger Bills vs GL Control Account 2010).
     */
    public function reconcileAccountsPayable(Organization $organization, ?Carbon $asOfDate = null): array
    {
        $asOf = $asOfDate ?? now();
        $dateStr = $asOf->toDateString();

        // 1. Subledger Total: Sum of outstanding payables from purchase bills
        $billsQuery = PurchaseBill::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->whereIn('status', ['posted', 'approved', 'partially_paid'])
            ->where('bill_date', '<=', $dateStr);

        $subledgerBills = $billsQuery->get();
        $subledgerTotal = '0.0000';
        foreach ($subledgerBills as $bill) {
            $total = (string) ($bill->net_payable ?? $bill->total_amount ?? '0.0000');
            $paid = (string) ($bill->amount_paid ?? '0.0000');
            $outstanding = bcsub($total, $paid, 4);
            $subledgerTotal = bcadd($subledgerTotal, $outstanding, 4);
        }

        // 2. GL Balance: Net credits to AP Control Account (2010 / 2000)
        $glBalance = '0.0000';
        $apAccount = Account::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->whereIn('code', ['2010', '2000'])
            ->first();

        $directPostingsCount = 0;
        if ($apAccount) {
            $glLines = JournalLine::withoutGlobalScopes()
                ->where('organization_id', $organization->id)
                ->where('account_id', $apAccount->id)
                ->whereHas('journalEntry', function ($q) use ($dateStr) {
                    $q->withoutGlobalScopes()
                        ->where('status', 'posted')
                        ->where('entry_date', '<=', $dateStr);
                })
                ->get();

            foreach ($glLines as $line) {
                $debit = (string) ($line->debit ?? '0.0000');
                $credit = (string) ($line->credit ?? '0.0000');
                $glBalance = bcadd($glBalance, bcsub($credit, $debit, 4), 4);
            }

            $directPostingsCount = JournalEntry::withoutGlobalScopes()
                ->where('organization_id', $organization->id)
                ->where('status', 'posted')
                ->where('source_type', 'manual')
                ->where('entry_date', '<=', $dateStr)
                ->whereHas('lines', function ($q) use ($apAccount) {
                    $q->withoutGlobalScopes()->where('account_id', $apAccount->id);
                })
                ->count();
        }

        $variance = bcsub($subledgerTotal, $glBalance, 4);
        $isReconciled = (bccomp($variance, '0.0000', 4) === 0);

        return [
            'subledger_type' => 'accounts_payable',
            'control_account_code' => $apAccount?->code ?? '2010',
            'control_account_name' => $apAccount?->name ?? 'Accounts Payable',
            'as_of_date' => $dateStr,
            'subledger_balance' => $subledgerTotal,
            'gl_balance' => $glBalance,
            'variance' => $variance,
            'status' => $isReconciled ? 'reconciled' : 'discrepancy',
            'open_subledger_items_count' => $subledgerBills->count(),
            'direct_manual_postings_count' => $directPostingsCount,
        ];
    }

    /**
     * Reconcile Bank Account Subledger vs GL Cash & Bank Accounts (1010, 1020).
     */
    public function reconcileBankToGL(Organization $organization, ?Carbon $asOfDate = null): array
    {
        $asOf = $asOfDate ?? now();
        $dateStr = $asOf->toDateString();

        // 1. Subledger Total: Sum of active BankAccount balances
        $bankAccounts = BankAccount::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->where('is_active', true)
            ->get();

        $subledgerTotal = '0.0000';
        foreach ($bankAccounts as $acc) {
            $subledgerTotal = bcadd($subledgerTotal, (string) ($acc->current_balance ?? '0.0000'), 4);
        }

        // 2. GL Balance: Net debits to Bank GL Accounts (1020)
        $glBalance = '0.0000';
        $bankGlAccounts = Account::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->whereIn('code', ['1020'])
            ->pluck('id');

        if ($bankGlAccounts->isNotEmpty()) {
            $glLines = JournalLine::withoutGlobalScopes()
                ->where('organization_id', $organization->id)
                ->whereIn('account_id', $bankGlAccounts)
                ->whereHas('journalEntry', function ($q) use ($dateStr) {
                    $q->withoutGlobalScopes()
                        ->where('status', 'posted')
                        ->where('entry_date', '<=', $dateStr);
                })
                ->get();

            foreach ($glLines as $line) {
                $debit = (string) ($line->debit ?? '0.0000');
                $credit = (string) ($line->credit ?? '0.0000');
                $glBalance = bcadd($glBalance, bcsub($debit, $credit, 4), 4);
            }
        }

        $variance = bcsub($subledgerTotal, $glBalance, 4);
        $isReconciled = (bccomp($variance, '0.0000', 4) === 0);

        return [
            'subledger_type' => 'bank_accounts',
            'control_account_code' => '1020',
            'control_account_name' => 'Bank Accounts',
            'as_of_date' => $dateStr,
            'subledger_balance' => $subledgerTotal,
            'gl_balance' => $glBalance,
            'variance' => $variance,
            'status' => $isReconciled ? 'reconciled' : 'discrepancy',
            'bank_accounts_count' => $bankAccounts->count(),
        ];
    }

    /**
     * Run full automated subledger to GL reconciliation report across all control accounts.
     */
    public function runFullReconciliation(Organization $organization, ?Carbon $asOfDate = null): array
    {
        $asOf = $asOfDate ?? now();

        $ar = $this->reconcileAccountsReceivable($organization, $asOf);
        $ap = $this->reconcileAccountsPayable($organization, $asOf);
        $bank = $this->reconcileBankToGL($organization, $asOf);

        $hasDiscrepancies = ($ar['status'] !== 'reconciled' || $ap['status'] !== 'reconciled' || $bank['status'] !== 'reconciled');

        return [
            'organization_id' => $organization->id,
            'as_of_date' => $asOf->toDateString(),
            'overall_status' => $hasDiscrepancies ? 'discrepancy_detected' : 'fully_reconciled',
            'reconciliations' => [
                'accounts_receivable' => $ar,
                'accounts_payable' => $ap,
                'bank_accounts' => $bank,
            ],
            'generated_at' => now()->toIso8601String(),
        ];
    }
}
