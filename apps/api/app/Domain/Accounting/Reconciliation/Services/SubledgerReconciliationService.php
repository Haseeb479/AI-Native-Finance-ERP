<?php

namespace App\Domain\Accounting\Reconciliation\Services;

use App\Domain\Accounting\ChartOfAccounts\Models\Account;
use App\Domain\Accounting\Journal\Models\JournalEntry;
use App\Domain\Accounting\Journal\Models\JournalLine;
use App\Domain\Banking\Models\BankAccount;
use App\Domain\Close\Models\FixedAsset;
use App\Domain\Inventory\Models\InventoryValuationLayer;
use App\Domain\Organization\Models\Organization;
use App\Domain\Purchasing\Models\PurchaseBill;
use App\Domain\Revenue\Models\RevenueSchedule;
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
     * Reconcile Inventory Valuation Subledger vs GL Inventory Control Account (1070 / 1200 / 1050) (P1-25).
     */
    public function reconcileInventoryToGL(Organization $organization, ?Carbon $asOfDate = null): array
    {
        $asOf = $asOfDate ?? now();
        $dateStr = $asOf->toDateString();

        // 1. Subledger Total: Sum of (quantity_remaining * unit_cost) from InventoryValuationLayer
        $layers = InventoryValuationLayer::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->where('quantity_remaining', '>', 0)
            ->where('received_at', '<=', $asOf->endOfDay())
            ->get();

        $subledgerTotal = '0.0000';
        foreach ($layers as $layer) {
            $layerVal = bcmul((string) $layer->quantity_remaining, (string) $layer->unit_cost, 4);
            $subledgerTotal = bcadd($subledgerTotal, $layerVal, 4);
        }

        // 2. GL Balance: Net debits to Inventory GL Accounts (1070 / 1200 / 1050)
        $glBalance = '0.0000';
        $inventoryAccount = Account::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->whereIn('code', ['1070', '1200', '1050'])
            ->first();

        $directPostingsCount = 0;
        if ($inventoryAccount) {
            $glLines = JournalLine::withoutGlobalScopes()
                ->where('organization_id', $organization->id)
                ->where('account_id', $inventoryAccount->id)
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

            $directPostingsCount = JournalEntry::withoutGlobalScopes()
                ->where('organization_id', $organization->id)
                ->where('status', 'posted')
                ->where('source_type', 'manual')
                ->where('entry_date', '<=', $dateStr)
                ->whereHas('lines', function ($q) use ($inventoryAccount) {
                    $q->withoutGlobalScopes()->where('account_id', $inventoryAccount->id);
                })
                ->count();
        }

        $variance = bcsub($subledgerTotal, $glBalance, 4);
        $isReconciled = (bccomp($variance, '0.0000', 4) === 0);

        return [
            'subledger_type' => 'inventory',
            'control_account_code' => $inventoryAccount?->code ?? '1070',
            'control_account_name' => $inventoryAccount?->name ?? 'Merchandise Inventory',
            'as_of_date' => $dateStr,
            'subledger_balance' => $subledgerTotal,
            'gl_balance' => $glBalance,
            'variance' => $variance,
            'status' => $isReconciled ? 'reconciled' : 'discrepancy',
            'valuation_layers_count' => $layers->count(),
            'direct_manual_postings_count' => $directPostingsCount,
        ];
    }

    /**
     * Reconcile Revenue Recognition Subledger (Recognized Schedules vs GL Revenue 4010) (P1-25).
     */
    public function reconcileRevenueRecognitionToGL(Organization $organization, ?Carbon $asOfDate = null): array
    {
        $asOf = $asOfDate ?? now();
        $dateStr = $asOf->toDateString();

        // 1. Subledger Total: Sum of recognized schedules through asOf date
        $schedules = RevenueSchedule::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->where('status', 'recognized')
            ->where('schedule_date', '<=', $dateStr)
            ->get();

        $subledgerTotal = '0.0000';
        foreach ($schedules as $sched) {
            $subledgerTotal = bcadd($subledgerTotal, (string) ($sched->amount ?? '0.0000'), 4);
        }

        // 2. GL Balance: Net credits to Sales Revenue (4010 / 4000)
        $glBalance = '0.0000';
        $revenueAccounts = Account::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->whereIn('code', ['4010', '4000'])
            ->pluck('id');

        if ($revenueAccounts->isNotEmpty()) {
            $glLines = JournalLine::withoutGlobalScopes()
                ->where('organization_id', $organization->id)
                ->whereIn('account_id', $revenueAccounts)
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
        }

        $variance = bcsub($subledgerTotal, $glBalance, 4);
        $isReconciled = (bccomp($variance, '0.0000', 4) === 0);

        return [
            'subledger_type' => 'revenue_recognition',
            'control_account_code' => '4010',
            'control_account_name' => 'Sales Revenue',
            'as_of_date' => $dateStr,
            'subledger_balance' => $subledgerTotal,
            'gl_balance' => $glBalance,
            'variance' => $variance,
            'status' => $isReconciled ? 'reconciled' : 'discrepancy',
            'recognized_schedules_count' => $schedules->count(),
        ];
    }

    /**
     * Reconcile Fixed Assets Register (Active Asset Cost vs GL Fixed Assets 1510 / 1500) (P1-25).
     */
    public function reconcileFixedAssetsToGL(Organization $organization, ?Carbon $asOfDate = null): array
    {
        $asOf = $asOfDate ?? now();
        $dateStr = $asOf->toDateString();

        // 1. Subledger Total: Sum of active FixedAsset purchase_cost
        $assets = FixedAsset::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->where('purchase_date', '<=', $dateStr)
            ->where('status', 'active')
            ->get();

        $subledgerTotal = '0.0000';
        foreach ($assets as $asset) {
            $subledgerTotal = bcadd($subledgerTotal, (string) ($asset->purchase_cost ?? '0.0000'), 4);
        }

        // 2. GL Balance: Net debits to Fixed Asset GL Accounts (1510, 1520, 1500)
        $glBalance = '0.0000';
        $assetAccounts = Account::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->whereIn('code', ['1510', '1520', '1500'])
            ->pluck('id');

        if ($assetAccounts->isNotEmpty()) {
            $glLines = JournalLine::withoutGlobalScopes()
                ->where('organization_id', $organization->id)
                ->whereIn('account_id', $assetAccounts)
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
            'subledger_type' => 'fixed_assets',
            'control_account_code' => '1510',
            'control_account_name' => 'Fixed Assets',
            'as_of_date' => $dateStr,
            'subledger_balance' => $subledgerTotal,
            'gl_balance' => $glBalance,
            'variance' => $variance,
            'status' => $isReconciled ? 'reconciled' : 'discrepancy',
            'active_assets_count' => $assets->count(),
        ];
    }

    /**
     * Reconcile Tax Subledgers (Output Tax from Invoices & Input Tax from Bills vs GL 2020 & 1050) (P1-25).
     */
    public function reconcileTaxToGL(Organization $organization, ?Carbon $asOfDate = null): array
    {
        $asOf = $asOfDate ?? now();
        $dateStr = $asOf->toDateString();

        // 1. Subledger Total: Output sales tax from posted invoices
        $outputTax = SalesInvoice::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->whereIn('status', ['posted', 'paid', 'partially_paid'])
            ->where('issue_date', '<=', $dateStr)
            ->sum('tax_amount');

        // Input sales tax from posted bills
        $inputTax = PurchaseBill::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->whereIn('status', ['posted', 'paid', 'partially_paid'])
            ->where('bill_date', '<=', $dateStr)
            ->sum('tax_amount');

        $subledgerNetTax = bcsub((string) $outputTax, (string) $inputTax, 4);

        // 2. GL Balance: Net balance of Sales Tax Payable (2020, normal credit) - Input Tax (1050, normal debit)
        $outputTaxAccount = Account::withoutGlobalScopes()->where('organization_id', $organization->id)->where('code', '2020')->first();
        $inputTaxAccount = Account::withoutGlobalScopes()->where('organization_id', $organization->id)->where('code', '1050')->first();

        $glOutput = '0.0000';
        if ($outputTaxAccount) {
            $lines = JournalLine::withoutGlobalScopes()
                ->where('organization_id', $organization->id)
                ->where('account_id', $outputTaxAccount->id)
                ->whereHas('journalEntry', function ($q) use ($dateStr) {
                    $q->withoutGlobalScopes()->where('status', 'posted')->where('entry_date', '<=', $dateStr);
                })
                ->get();
            foreach ($lines as $l) {
                $glOutput = bcadd($glOutput, bcsub((string) $l->credit, (string) $l->debit, 4), 4);
            }
        }

        $glInput = '0.0000';
        if ($inputTaxAccount) {
            $lines = JournalLine::withoutGlobalScopes()
                ->where('organization_id', $organization->id)
                ->where('account_id', $inputTaxAccount->id)
                ->whereHas('journalEntry', function ($q) use ($dateStr) {
                    $q->withoutGlobalScopes()->where('status', 'posted')->where('entry_date', '<=', $dateStr);
                })
                ->get();
            foreach ($lines as $l) {
                $glInput = bcadd($glInput, bcsub((string) $l->debit, (string) $l->credit, 4), 4);
            }
        }

        $glNetTax = bcsub($glOutput, $glInput, 4);
        $variance = bcsub($subledgerNetTax, $glNetTax, 4);
        $isReconciled = (bccomp($variance, '0.0000', 4) === 0);

        return [
            'subledger_type' => 'tax',
            'control_account_code' => '2020 / 1050',
            'control_account_name' => 'Sales Tax Net Liability',
            'as_of_date' => $dateStr,
            'subledger_balance' => $subledgerNetTax,
            'gl_balance' => $glNetTax,
            'variance' => $variance,
            'status' => $isReconciled ? 'reconciled' : 'discrepancy',
            'output_tax_subledger' => (string) $outputTax,
            'input_tax_subledger' => (string) $inputTax,
        ];
    }

    /**
     * Run full automated subledger to GL reconciliation report across all 7 control accounts (P1-25).
     */
    public function runFullReconciliation(Organization $organization, ?Carbon $asOfDate = null): array
    {
        $asOf = $asOfDate ?? now();

        $ar = $this->reconcileAccountsReceivable($organization, $asOf);
        $ap = $this->reconcileAccountsPayable($organization, $asOf);
        $bank = $this->reconcileBankToGL($organization, $asOf);
        $inventory = $this->reconcileInventoryToGL($organization, $asOf);
        $revenue = $this->reconcileRevenueRecognitionToGL($organization, $asOf);
        $fixedAssets = $this->reconcileFixedAssetsToGL($organization, $asOf);
        $tax = $this->reconcileTaxToGL($organization, $asOf);

        $reconciliations = [
            'accounts_receivable' => $ar,
            'accounts_payable' => $ap,
            'bank_accounts' => $bank,
            'inventory' => $inventory,
            'revenue_recognition' => $revenue,
            'fixed_assets' => $fixedAssets,
            'tax' => $tax,
        ];

        $hasDiscrepancies = false;
        $recommendations = [];

        foreach ($reconciliations as $module => $res) {
            if ($res['status'] !== 'reconciled') {
                $hasDiscrepancies = true;
                $recommendations[] = [
                    'module' => $module,
                    'variance' => $res['variance'],
                    'message' => "Discrepancy of {$res['variance']} in {$res['control_account_name']}. Investigate unposted transactions, unlinked items, or manual journal adjustments.",
                    'action' => 'review_and_post_pending_transactions',
                ];
            }
        }

        return [
            'organization_id' => $organization->id,
            'as_of_date' => $asOf->toDateString(),
            'overall_status' => $hasDiscrepancies ? 'discrepancy_detected' : 'fully_reconciled',
            'reconciliations' => $reconciliations,
            'discrepancy_count' => count($recommendations),
            'recommendations' => $recommendations,
            'actionable_recommendations' => $recommendations,
            'generated_at' => now()->toIso8601String(),
        ];
    }
}
