<?php

namespace App\Domain\Purchasing\Services;

use App\Domain\Accounting\ChartOfAccounts\Models\Account;
use App\Domain\Accounting\Posting\Services\PostingEngine;
use App\Domain\Organization\Models\Organization;
use App\Domain\Purchasing\Models\PurchaseBill;
use App\Domain\Purchasing\Models\PurchaseBillLine;
use App\Domain\Purchasing\Models\Vendor;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class BillService
{
    public function __construct(private readonly PostingEngine $postingEngine)
    {
    }

    /**
     * Generate sequential bill number per tenant (e.g. BILL-2025-00001).
     */
    public function generateBillNumber(Organization $organization, Carbon $date): string
    {
        $year = $date->format('Y');
        $prefix = "BILL-{$year}-";

        $lastBill = PurchaseBill::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->where('bill_number', 'LIKE', "{$prefix}%")
            ->orderBy('bill_number', 'desc')
            ->first();

        if ($lastBill) {
            $lastSequence = (int) substr($lastBill->bill_number, strlen($prefix));
            $nextSequence = $lastSequence + 1;
        } else {
            $nextSequence = 1;
        }

        return $prefix . str_pad((string) $nextSequence, 5, '0', STR_PAD_LEFT);
    }

    /**
     * Create a draft purchase bill with line items and WHT deduction.
     */
    public function createBill(Organization $organization, array $data, User $user): PurchaseBill
    {
        $vendor = Vendor::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->findOrFail($data['vendor_id']);

        // Duplicate Vendor Invoice Reference Check
        if (! empty($data['vendor_invoice_ref'])) {
            $duplicate = PurchaseBill::withoutGlobalScopes()
                ->where('organization_id', $organization->id)
                ->where('vendor_id', $vendor->id)
                ->where('vendor_invoice_ref', $data['vendor_invoice_ref'])
                ->exists();

            if ($duplicate) {
                throw new InvalidArgumentException("A bill with vendor invoice reference '{$data['vendor_invoice_ref']}' already exists for this vendor.");
            }
        }

        $billDate = Carbon::parse($data['bill_date']);
        $dueDate = ! empty($data['due_date'])
            ? Carbon::parse($data['due_date'])
            : (clone $billDate)->addDays($vendor->payment_terms_days ?? 30);

        $billNumber = $data['bill_number'] ?? $this->generateBillNumber($organization, $billDate);

        return DB::transaction(function () use ($organization, $vendor, $data, $billDate, $dueDate, $billNumber, $user) {
            $subtotal = 0.0;
            $computedLines = [];
            $lineNumber = 1;

            foreach ($data['lines'] as $line) {
                $qty = (float) ($line['quantity'] ?? 1.0);
                $unitPrice = (float) ($line['unit_price'] ?? 0.0);
                $lineSubtotal = round($qty * $unitPrice, 4);

                $subtotal += $lineSubtotal;

                $computedLines[] = [
                    'organization_id' => $organization->id,
                    'expense_account_id' => $line['expense_account_id'],
                    'line_number' => $lineNumber++,
                    'description' => $line['description'],
                    'quantity' => $qty,
                    'unit_price' => $unitPrice,
                    'subtotal' => $lineSubtotal,
                ];
            }

            $whtRate = (float) ($data['wht_rate'] ?? 0.0);
            $whtAmount = round($subtotal * ($whtRate / 100.0), 4);
            $totalAmount = $subtotal;
            $netPayable = round($totalAmount - $whtAmount, 4);

            $bill = PurchaseBill::withoutGlobalScopes()->create([
                'organization_id' => $organization->id,
                'vendor_id' => $vendor->id,
                'bill_number' => $billNumber,
                'vendor_invoice_ref' => $data['vendor_invoice_ref'] ?? null,
                'bill_date' => $billDate->toDateString(),
                'due_date' => $dueDate->toDateString(),
                'status' => 'draft',
                'currency' => $data['currency'] ?? $organization->base_currency ?? 'PKR',
                'exchange_rate' => $data['exchange_rate'] ?? 1.000000,
                'subtotal' => $subtotal,
                'wht_rate' => $whtRate,
                'wht_amount' => $whtAmount,
                'tax_amount' => 0.0000,
                'total_amount' => $totalAmount,
                'net_payable' => $netPayable,
                'amount_paid' => 0.0000,
                'notes' => $data['notes'] ?? null,
                'created_by' => $user->id,
            ]);

            foreach ($computedLines as $lineAttrs) {
                $lineAttrs['purchase_bill_id'] = $bill->id;
                PurchaseBillLine::withoutGlobalScopes()->create($lineAttrs);
            }

            return $bill->load(['vendor', 'lines.expenseAccount']);
        });
    }

    /**
     * Submit purchase bill for approval.
     */
    public function submitForApproval(PurchaseBill $bill, User $user): PurchaseBill
    {
        if (! $bill->isDraft() && ! $bill->isRejected()) {
            throw new InvalidArgumentException("Only draft or rejected bills can be submitted for approval.");
        }

        $bill->update([
            'status' => 'pending_approval',
            'rejection_reason' => null,
        ]);

        return $bill->fresh();
    }

    /**
     * Approve purchase bill.
     */
    public function approveBill(PurchaseBill $bill, User $user): PurchaseBill
    {
        if (! $bill->isPendingApproval()) {
            throw new InvalidArgumentException("Only purchase bills pending approval can be approved.");
        }

        $oldStatus = $bill->status;

        $bill->update([
            'status' => 'approved',
            'approved_by' => $user->id,
            'approved_at' => now(),
        ]);

        if (class_exists(\App\Domain\Audit\Services\AuditService::class)) {
            app(\App\Domain\Audit\Services\AuditService::class)->log(
                $bill->organization_id,
                $user,
                'bill:approved',
                $bill,
                ['status' => $oldStatus],
                ['status' => 'approved', 'approved_at' => $bill->approved_at->toIso8601String()]
            );
        }

        return $bill->fresh();
    }

    /**
     * Reject purchase bill with reason.
     */
    public function rejectBill(PurchaseBill $bill, User $user, string $reason): PurchaseBill
    {
        if (! $bill->isPendingApproval()) {
            throw new InvalidArgumentException("Only purchase bills pending approval can be rejected.");
        }

        $oldStatus = $bill->status;

        $bill->update([
            'status' => 'rejected',
            'rejected_by' => $user->id,
            'rejected_at' => now(),
            'rejection_reason' => $reason,
        ]);

        if (class_exists(\App\Domain\Audit\Services\AuditService::class)) {
            app(\App\Domain\Audit\Services\AuditService::class)->log(
                $bill->organization_id,
                $user,
                'bill:rejected',
                $bill,
                ['status' => $oldStatus],
                ['status' => 'rejected', 'rejected_at' => $bill->rejected_at->toIso8601String(), 'reason' => $reason]
            );
        }

        return $bill->fresh();
    }

    /**
     * Post a purchase bill: creates and posts a General Ledger double-entry journal.
     */
    public function postBill(PurchaseBill $bill, User $user): PurchaseBill
    {
        if (! in_array($bill->status, ['draft', 'approved'])) {
            throw new InvalidArgumentException("Only draft or approved purchase bills can be posted.");
        }

        $organization = Organization::findOrFail($bill->organization_id);

        // Resolve Accounts Payable (2010)
        $apAccount = Account::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->where('code', '2010')
            ->first();

        if (! $apAccount) {
            $apAccount = Account::withoutGlobalScopes()
                ->where('organization_id', $organization->id)
                ->where('classification', 'liability')
                ->where('is_reconcilable', true)
                ->firstOrFail();
        }

        $journalLines = [];

        // 1. Debit Expense / Asset accounts for line items (Total Debit = Gross Bill Amount)
        foreach ($bill->lines as $line) {
            $journalLines[] = [
                'account_id' => $line->expense_account_id,
                'description' => "Bill {$bill->bill_number} - " . ($line->description ?? "Item {$line->line_number}"),
                'debit' => (float) $line->subtotal,
                'credit' => 0.0000,
            ];
        }

        // 2. Credit WHT Payable (2030) if WHT withheld
        if ((float) $bill->wht_amount > 0) {
            $whtAccount = Account::withoutGlobalScopes()
                ->where('organization_id', $organization->id)
                ->where('code', '2030') // WHT Payable on Vendor Payments
                ->first();

            if (! $whtAccount) {
                $whtAccount = Account::withoutGlobalScopes()
                    ->where('organization_id', $organization->id)
                    ->where('classification', 'liability')
                    ->firstOrFail();
            }

            $journalLines[] = [
                'account_id' => $whtAccount->id,
                'description' => "WHT Deducted on Bill {$bill->bill_number} ({$bill->wht_rate}%)",
                'debit' => 0.0000,
                'credit' => (float) $bill->wht_amount,
            ];
        }

        // 3. Credit Accounts Payable for Net Payable owed to vendor
        $journalLines[] = [
            'account_id' => $apAccount->id,
            'description' => "Net Payable to {$bill->vendor->name} for Bill {$bill->bill_number}",
            'debit' => 0.0000,
            'credit' => (float) $bill->net_payable,
        ];

        return DB::transaction(function () use ($organization, $bill, $journalLines, $user) {
            $oldStatus = $bill->status;

            $draftJournal = $this->postingEngine->createDraft($organization, [
                'entry_date' => $bill->bill_date->toDateString(),
                'source_type' => 'bill',
                'source_id' => $bill->id,
                'description' => "Purchase Bill {$bill->bill_number} posted from vendor {$bill->vendor->name}",
                'currency' => $bill->currency,
                'lines' => $journalLines,
            ], $user);

            $postedJournal = $this->postingEngine->postEntry($draftJournal, $user);

            $bill->update([
                'status' => 'received',
                'posted_at' => now(),
                'journal_entry_id' => $postedJournal->id,
            ]);

            if (class_exists(\App\Domain\Audit\Services\AuditService::class)) {
                app(\App\Domain\Audit\Services\AuditService::class)->log(
                    $bill->organization_id,
                    $user,
                    'bill:posted',
                    $bill,
                    ['status' => $oldStatus],
                    [
                        'status' => 'received',
                        'posted_at' => $bill->posted_at->toIso8601String(),
                        'journal_entry_id' => $postedJournal->id,
                        'total_amount' => (string) $bill->total_amount,
                    ]
                );
            }

            return $bill->fresh(['vendor', 'lines.expenseAccount', 'journalEntry']);
        });
    }

    /**
     * Record a payment disbursement to the vendor against a bill and post GL payment journal.
     */
    public function recordPayment(PurchaseBill $bill, array $paymentData, User $user): PurchaseBill
    {
        if ($bill->isDraft()) {
            throw new InvalidArgumentException("Cannot record payment on an unposted draft bill.");
        }

        $amount = (float) $paymentData['amount'];
        $balanceDue = $bill->balanceDue();

        if ($amount <= 0 || $amount > round($balanceDue + 0.01, 2)) {
            throw new InvalidArgumentException(sprintf(
                'Payment amount (PKR %.2f) cannot exceed outstanding bill balance (PKR %.2f).',
                $amount,
                $balanceDue
            ));
        }

        $organization = Organization::findOrFail($bill->organization_id);

        // Resolve Bank or Cash Account
        $bankAccountId = $paymentData['bank_account_id'] ?? null;
        if (! $bankAccountId) {
            $bankAccount = Account::withoutGlobalScopes()
                ->where('organization_id', $organization->id)
                ->where('code', '1020') // Meezan Bank
                ->first();
            $bankAccountId = $bankAccount?->id;
        }

        // Resolve AP Account (2010)
        $apAccount = Account::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->where('code', '2010')
            ->firstOrFail();

        $paymentDate = ! empty($paymentData['payment_date'])
            ? Carbon::parse($paymentData['payment_date'])
            : $bill->bill_date;

        return DB::transaction(function () use ($organization, $bill, $amount, $bankAccountId, $apAccount, $paymentDate, $paymentData, $user) {
            // Post Disbursement Journal: Debit AP, Credit Bank
            $journalDraft = $this->postingEngine->createDraft($organization, [
                'entry_date' => $paymentDate->toDateString(),
                'source_type' => 'vendor_payment',
                'source_id' => $bill->id,
                'description' => "Payment disbursed for Bill {$bill->bill_number} to {$bill->vendor->name}. Ref: " . ($paymentData['reference'] ?? 'Cheque/Wire'),
                'currency' => $bill->currency,
                'lines' => [
                    [
                        'account_id' => $apAccount->id,
                        'description' => "AP cleared for Bill {$bill->bill_number}",
                        'debit' => $amount,
                        'credit' => 0.0000,
                    ],
                    [
                        'account_id' => $bankAccountId,
                        'description' => "Disbursement for Bill {$bill->bill_number}",
                        'debit' => 0.0000,
                        'credit' => $amount,
                    ],
                ],
            ], $user);

            $this->postingEngine->postEntry($journalDraft, $user);

            // Update bill payment status
            $newPaid = round((float) $bill->amount_paid + $amount, 4);
            $newBalance = max(0.0, (float) $bill->net_payable - $newPaid);
            $newStatus = $newBalance < 0.0001 ? 'paid' : 'partial';

            $bill->update([
                'amount_paid' => $newPaid,
                'status' => $newStatus,
            ]);

            return $bill->fresh(['vendor', 'lines', 'journalEntry']);
        });
    }
}
