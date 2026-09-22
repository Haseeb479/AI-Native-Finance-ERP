<?php

namespace App\Domain\Sales\Services;

use App\Domain\Accounting\ChartOfAccounts\Models\Account;
use App\Domain\Accounting\Posting\Services\PostingEngine;
use App\Domain\Organization\Models\Organization;
use App\Domain\Sales\Models\Customer;
use App\Domain\Sales\Models\SalesInvoice;
use App\Domain\Sales\Models\SalesInvoiceLine;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class InvoiceService
{
    public function __construct(private readonly PostingEngine $postingEngine)
    {
    }

    /**
     * Generate sequential invoice number per tenant (e.g. INV-2025-00001).
     */
    public function generateInvoiceNumber(Organization $organization, Carbon $date): string
    {
        $year = $date->format('Y');
        $prefix = "INV-{$year}-";

        $lastInvoice = SalesInvoice::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->where('invoice_number', 'LIKE', "{$prefix}%")
            ->orderBy('invoice_number', 'desc')
            ->first();

        if ($lastInvoice) {
            $lastSequence = (int) substr($lastInvoice->invoice_number, strlen($prefix));
            $nextSequence = $lastSequence + 1;
        } else {
            $nextSequence = 1;
        }

        return $prefix . str_pad((string) $nextSequence, 5, '0', STR_PAD_LEFT);
    }

    /**
     * Create a draft sales invoice with lines and tax computations.
     */
    public function createInvoice(Organization $organization, array $data, User $user): SalesInvoice
    {
        $customer = Customer::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->findOrFail($data['customer_id']);

        $issueDate = Carbon::parse($data['issue_date']);
        $dueDate = ! empty($data['due_date'])
            ? Carbon::parse($data['due_date'])
            : (clone $issueDate)->addDays($customer->payment_terms_days ?? 30);

        $invoiceNumber = $data['invoice_number'] ?? $this->generateInvoiceNumber($organization, $issueDate);

        return DB::transaction(function () use ($organization, $customer, $data, $issueDate, $dueDate, $invoiceNumber, $user) {
            $subtotal = 0.0;
            $taxAmount = 0.0;

            $computedLines = [];
            $lineNumber = 1;

            foreach ($data['lines'] as $line) {
                $qty = (float) ($line['quantity'] ?? 1.0);
                $unitPrice = (float) ($line['unit_price'] ?? 0.0);
                $lineSubtotal = round($qty * $unitPrice, 4);
                $lineTaxRate = (float) ($line['tax_rate'] ?? 0.0);
                $lineTax = round($lineSubtotal * ($lineTaxRate / 100.0), 4);
                $lineTotal = round($lineSubtotal + $lineTax, 4);

                $subtotal += $lineSubtotal;
                $taxAmount += $lineTax;

                $computedLines[] = [
                    'organization_id' => $organization->id,
                    'revenue_account_id' => $line['revenue_account_id'],
                    'line_number' => $lineNumber++,
                    'description' => $line['description'],
                    'quantity' => $qty,
                    'unit_price' => $unitPrice,
                    'tax_rate' => $lineTaxRate,
                    'tax_amount' => $lineTax,
                    'subtotal' => $lineSubtotal,
                    'total' => $lineTotal,
                ];
            }

            $totalAmount = round($subtotal + $taxAmount, 4);

            $invoice = SalesInvoice::withoutGlobalScopes()->create([
                'organization_id' => $organization->id,
                'customer_id' => $customer->id,
                'invoice_number' => $invoiceNumber,
                'issue_date' => $issueDate->toDateString(),
                'due_date' => $dueDate->toDateString(),
                'status' => 'draft',
                'currency' => $data['currency'] ?? $organization->base_currency ?? 'PKR',
                'exchange_rate' => $data['exchange_rate'] ?? 1.000000,
                'subtotal' => $subtotal,
                'tax_rate' => $computedLines[0]['tax_rate'] ?? 0.0,
                'tax_amount' => $taxAmount,
                'total_amount' => $totalAmount,
                'amount_paid' => 0.0000,
                'notes' => $data['notes'] ?? null,
                'terms' => $data['terms'] ?? null,
                'created_by' => $user->id,
            ]);

            foreach ($computedLines as $lineAttrs) {
                $lineAttrs['sales_invoice_id'] = $invoice->id;
                SalesInvoiceLine::withoutGlobalScopes()->create($lineAttrs);
            }

            return $invoice->load(['customer', 'lines.revenueAccount']);
        });
    }

    /**
     * Post a sales invoice: creates and posts a General Ledger double-entry journal.
     */
    public function postInvoice(SalesInvoice $invoice, User $user): SalesInvoice
    {
        if (! $invoice->isDraft()) {
            throw new InvalidArgumentException("Only draft invoices can be posted.");
        }

        $organization = Organization::findOrFail($invoice->organization_id);

        // Resolve AR Account (1030)
        $arAccount = Account::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->where('code', '1030')
            ->first();

        if (! $arAccount) {
            $arAccount = Account::withoutGlobalScopes()
                ->where('organization_id', $organization->id)
                ->where('classification', 'asset')
                ->where('is_reconcilable', true)
                ->firstOrFail();
        }

        // Build double-entry lines
        $journalLines = [];

        // 1. Debit Accounts Receivable for gross invoice total
        $journalLines[] = [
            'account_id' => $arAccount->id,
            'description' => "Invoice {$invoice->invoice_number} - {$invoice->customer->name}",
            'debit' => (float) $invoice->total_amount,
            'credit' => 0.0000,
        ];

        // 2. Credit Revenue accounts for line subtotals
        foreach ($invoice->lines as $line) {
            $journalLines[] = [
                'account_id' => $line->revenue_account_id,
                'description' => "Revenue: " . ($line->description ?? "Item {$line->line_number}"),
                'debit' => 0.0000,
                'credit' => (float) $line->subtotal,
            ];
        }

        // 3. Credit Sales Tax Payable (2020) if tax > 0
        if ((float) $invoice->tax_amount > 0) {
            $taxAccount = Account::withoutGlobalScopes()
                ->where('organization_id', $organization->id)
                ->where('code', '2020')
                ->first();

            if (! $taxAccount) {
                $taxAccount = Account::withoutGlobalScopes()
                    ->where('organization_id', $organization->id)
                    ->where('classification', 'liability')
                    ->firstOrFail();
            }

            $journalLines[] = [
                'account_id' => $taxAccount->id,
                'description' => "Output Sales Tax on Invoice {$invoice->invoice_number}",
                'debit' => 0.0000,
                'credit' => (float) $invoice->tax_amount,
            ];
        }

        return DB::transaction(function () use ($organization, $invoice, $journalLines, $user) {
            // Create and post balanced journal
            $draftJournal = $this->postingEngine->createDraft($organization, [
                'entry_date' => $invoice->issue_date->toDateString(),
                'source_type' => 'invoice',
                'source_id' => $invoice->id,
                'description' => "Sales Invoice {$invoice->invoice_number} posted for customer {$invoice->customer->name}",
                'currency' => $invoice->currency,
                'lines' => $journalLines,
            ], $user);

            $postedJournal = $this->postingEngine->postEntry($draftJournal, $user);

            $invoice->update([
                'status' => 'sent',
                'posted_at' => now(),
                'journal_entry_id' => $postedJournal->id,
            ]);

            return $invoice->fresh(['customer', 'lines.revenueAccount', 'journalEntry']);
        });
    }

    /**
     * Record a customer payment against an invoice and post the GL payment journal.
     */
    public function recordPayment(SalesInvoice $invoice, array $paymentData, User $user): SalesInvoice
    {
        if ($invoice->isDraft()) {
            throw new InvalidArgumentException("Cannot record payment on an unposted draft invoice.");
        }

        $amount = (float) $paymentData['amount'];
        $balanceDue = $invoice->balanceDue();

        if ($amount <= 0 || $amount > round($balanceDue + 0.01, 2)) {
            throw new InvalidArgumentException(sprintf(
                'Payment amount (PKR %.2f) cannot exceed outstanding invoice balance (PKR %.2f).',
                $amount,
                $balanceDue
            ));
        }

        $organization = Organization::findOrFail($invoice->organization_id);

        // Resolve Bank or Cash Account
        $bankAccountId = $paymentData['bank_account_id'] ?? null;
        if (! $bankAccountId) {
            $bankAccount = Account::withoutGlobalScopes()
                ->where('organization_id', $organization->id)
                ->where('code', '1020') // Meezan Bank
                ->first();
            $bankAccountId = $bankAccount?->id;
        }

        // Resolve AR Account (1030)
        $arAccount = Account::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->where('code', '1030')
            ->firstOrFail();

        $paymentDate = ! empty($paymentData['payment_date'])
            ? Carbon::parse($paymentData['payment_date'])
            : $invoice->issue_date;

        return DB::transaction(function () use ($organization, $invoice, $amount, $bankAccountId, $arAccount, $paymentDate, $paymentData, $user) {
            // Post Payment Journal: Debit Bank, Credit AR
            $journalDraft = $this->postingEngine->createDraft($organization, [
                'entry_date' => $paymentDate->toDateString(),
                'source_type' => 'customer_payment',
                'source_id' => $invoice->id,
                'description' => "Payment received for Invoice {$invoice->invoice_number} from {$invoice->customer->name}. Ref: " . ($paymentData['reference'] ?? 'Direct Transfer'),
                'currency' => $invoice->currency,
                'lines' => [
                    [
                        'account_id' => $bankAccountId,
                        'description' => "Customer payment received - Invoice {$invoice->invoice_number}",
                        'debit' => $amount,
                        'credit' => 0.0000,
                    ],
                    [
                        'account_id' => $arAccount->id,
                        'description' => "AR cleared for Invoice {$invoice->invoice_number}",
                        'debit' => 0.0000,
                        'credit' => $amount,
                    ],
                ],
            ], $user);

            $this->postingEngine->postEntry($journalDraft, $user);

            // Update invoice payment state
            $newPaid = round((float) $invoice->amount_paid + $amount, 4);
            $newBalance = max(0.0, (float) $invoice->total_amount - $newPaid);
            $newStatus = $newBalance < 0.0001 ? 'paid' : 'partial';

            $invoice->update([
                'amount_paid' => $newPaid,
                'status' => $newStatus,
            ]);

            return $invoice->fresh(['customer', 'lines', 'journalEntry']);
        });
    }
}
