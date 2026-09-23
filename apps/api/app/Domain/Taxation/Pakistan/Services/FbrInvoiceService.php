<?php

namespace App\Domain\Taxation\Pakistan\Services;

use App\Domain\Audit\Services\AuditService;
use App\Domain\Organization\Models\Organization;
use App\Domain\Sales\Models\SalesInvoice;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Str;
use InvalidArgumentException;

class FbrInvoiceService
{
    public const DEFAULT_POS_ID = '100101';
    public const STANDARD_SALES_TAX_RATE = 18.00;
    public const DEFAULT_PCT_CODE = '9801.0000'; // Standard Pakistan Customs Tariff code for Services/Software

    public function __construct(private readonly ?AuditService $auditService = null)
    {
    }

    /**
     * Validate Pakistan Tax Identifiers (NTN, STRN, CNIC).
     */
    public function validateTaxId(string $type, string $taxId): array
    {
        $cleanId = preg_replace('/[^0-9]/', '', $taxId);
        $type = strtolower($type);

        switch ($type) {
            case 'ntn':
                // Pakistan NTN is 7 digits plus 1 check digit (total 8 digits) or 7-8 numeric digits
                $isValid = (strlen($cleanId) >= 7 && strlen($cleanId) <= 8);
                $formatted = strlen($cleanId) === 8 
                    ? substr($cleanId, 0, 7) . '-' . substr($cleanId, 7, 1)
                    : $cleanId;

                return [
                    'type' => 'NTN',
                    'raw' => $taxId,
                    'clean' => $cleanId,
                    'formatted' => $formatted,
                    'is_valid' => $isValid,
                    'description' => 'Pakistan National Tax Number (FBR)',
                ];

            case 'strn':
                // Pakistan STRN is 13 digits
                $isValid = (strlen($cleanId) === 13);

                return [
                    'type' => 'STRN',
                    'raw' => $taxId,
                    'clean' => $cleanId,
                    'formatted' => $cleanId,
                    'is_valid' => $isValid,
                    'description' => 'Sales Tax Registration Number',
                ];

            case 'cnic':
                // Pakistan CNIC is 13 digits: 5-7-1 format
                $isValid = (strlen($cleanId) === 13);
                $formatted = $isValid
                    ? substr($cleanId, 0, 5) . '-' . substr($cleanId, 5, 7) . '-' . substr($cleanId, 12, 1)
                    : $cleanId;

                return [
                    'type' => 'CNIC',
                    'raw' => $taxId,
                    'clean' => $cleanId,
                    'formatted' => $formatted,
                    'is_valid' => $isValid,
                    'description' => 'Computerized National Identity Card',
                ];

            default:
                throw new InvalidArgumentException("Unsupported tax ID type: {$type}. Supported types: NTN, STRN, CNIC.");
        }
    }

    /**
     * Build the standard FBR Digital Invoicing / POS JSON payload schema.
     */
    public function generateFbrPayload(SalesInvoice $invoice, ?string $fbrInvoiceNumber = null, ?string $posId = null): array
    {
        $posId = $posId ?? self::DEFAULT_POS_ID;
        $fbrNo = $fbrInvoiceNumber ?? $invoice->fbr_invoice_number ?? $this->generateFbrInvoiceNumber($posId);

        $customer = $invoice->customer;
        $issueDateTime = $invoice->issue_date ? $invoice->issue_date->format('Y-m-d 12:00:00') : now()->format('Y-m-d H:i:s');

        $items = [];
        foreach ($invoice->lines as $line) {
            $items[] = [
                'ItemCode' => 'ITEM-' . $line->line_number,
                'ItemName' => $line->description ?: 'Taxable Goods or Services',
                'PCTCode' => $line->pct_code ?: self::DEFAULT_PCT_CODE,
                'Quantity' => (float) $line->quantity,
                'TaxRate' => (float) ($line->tax_rate ?: self::STANDARD_SALES_TAX_RATE),
                'SaleValue' => (float) $line->subtotal,
                'TaxCharged' => (float) $line->tax_amount,
                'TotalAmount' => (float) $line->total,
                'Discount' => 0.00,
            ];
        }

        return [
            'InvoiceNumber' => $fbrNo,
            'POSID' => (int) $posId,
            'USIN' => $invoice->invoice_number,
            'DateTime' => $issueDateTime,
            'BuyerNTN' => $customer?->ntn ?? '',
            'BuyerCNIC' => '',
            'BuyerName' => $customer?->name ?? 'Walk-in Customer',
            'BuyerPhoneNumber' => $customer?->phone ?? '',
            'TotalSaleValue' => (float) $invoice->subtotal,
            'TotalTaxCharged' => (float) $invoice->tax_amount,
            'TotalBillAmount' => (float) $invoice->total_amount,
            'TotalQuantity' => (float) $invoice->lines->sum('quantity'),
            'Discount' => 0.00,
            'PaymentMode' => 4, // 1=Cash, 2=Card, 3=Cheque, 4=Bank Transfer
            'InvoiceType' => 1, // 1=New, 2=Debit, 3=Credit
            'Items' => $items,
        ];
    }

    /**
     * Generate the verification QR code string encoding FBR invoice metadata.
     */
    public function generateQrCodePayload(SalesInvoice $invoice, string $fbrInvoiceNumber, string $posId = self::DEFAULT_POS_ID): string
    {
        $dateStr = $invoice->issue_date ? $invoice->issue_date->format('Y-m-d') : now()->format('Y-m-d');
        $verifyUrl = "https://fbr.gov.pk/verify-invoice?no={$fbrInvoiceNumber}";

        return sprintf(
            "FBR_INV:%s|POS:%s|USIN:%s|AMT:%.2f|TAX:%.2f|DATE:%s|STATUS:FISCALIZED|VERIFY_URL:%s",
            $fbrInvoiceNumber,
            $posId,
            $invoice->invoice_number,
            (float) $invoice->total_amount,
            (float) $invoice->tax_amount,
            $dateStr,
            $verifyUrl
        );
    }

    /**
     * Generate a 16-18 digit compliant FBR invoice sequence number.
     */
    public function generateFbrInvoiceNumber(string $posId = self::DEFAULT_POS_ID): string
    {
        // Format: {POSID}{YYMMDD}{6 random digits} => 6 + 6 + 6 = 18 digits numeric identifier
        $datePart = now()->format('ymd');
        $randomPart = str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT);

        return "{$posId}{$datePart}{$randomPart}";
    }

    /**
     * Fiscalize an invoice with FBR Digital Invoicing / POS Integration.
     */
    public function fiscalizeInvoice(SalesInvoice $invoice, User $user, array $options = []): SalesInvoice
    {
        if ($invoice->isFbrFiscalized()) {
            throw new InvalidArgumentException("Invoice {$invoice->invoice_number} is already fiscalized with FBR (FBR No: {$invoice->fbr_invoice_number}).");
        }

        $posId = $options['pos_id'] ?? self::DEFAULT_POS_ID;
        $fbrInvoiceNumber = $this->generateFbrInvoiceNumber($posId);
        $payload = $this->generateFbrPayload($invoice, $fbrInvoiceNumber, $posId);
        $qrPayload = $this->generateQrCodePayload($invoice, $fbrInvoiceNumber, $posId);

        // Simulated FBR IMS (Invoice Monitoring System) Gateway response
        $fbrResponse = [
            'status' => 'success',
            'code' => 100,
            'fbr_invoice_number' => $fbrInvoiceNumber,
            'pos_id' => $posId,
            'usin' => $invoice->invoice_number,
            'received_at' => now()->toIso8601String(),
            'message' => 'Invoice successfully fiscalized with Federal Board of Revenue (FBR) IMS',
            'validation_digest' => hash('sha256', $qrPayload),
        ];

        $oldStatus = $invoice->fbr_status;

        $invoice->update([
            'fbr_invoice_number' => $fbrInvoiceNumber,
            'fbr_status' => 'fiscalized',
            'fbr_fiscalized_at' => now(),
            'fbr_qr_code' => $qrPayload,
            'fbr_response_data' => $fbrResponse,
        ]);

        if (class_exists(AuditService::class)) {
            $auditService = $this->auditService ?? app(AuditService::class);
            $auditService->log(
                $invoice->organization_id,
                $user,
                'invoice:fbr_fiscalized',
                $invoice,
                ['fbr_status' => $oldStatus],
                [
                    'fbr_status' => 'fiscalized',
                    'fbr_invoice_number' => $fbrInvoiceNumber,
                    'fiscalized_at' => $invoice->fbr_fiscalized_at->toIso8601String(),
                    'total_amount' => (string) $invoice->total_amount,
                    'tax_amount' => (string) $invoice->tax_amount,
                ]
            );
        }

        return $invoice->fresh(['customer', 'lines']);
    }

    /**
     * Get QR Code verification data for an invoice.
     */
    public function getQrCodeData(SalesInvoice $invoice): array
    {
        $fbrNo = $invoice->fbr_invoice_number;
        $qrString = $invoice->fbr_qr_code;

        if (! $qrString && $invoice->isFbrFiscalized()) {
            $qrString = $this->generateQrCodePayload($invoice, $fbrNo ?? 'UNKNOWN');
        }

        $verifyUrl = $fbrNo ? "https://fbr.gov.pk/verify-invoice?no={$fbrNo}" : null;

        return [
            'invoice_id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'fbr_invoice_number' => $fbrNo,
            'fbr_status' => $invoice->fbr_status,
            'fiscalized_at' => $invoice->fbr_fiscalized_at?->toIso8601String(),
            'qr_payload' => $qrString,
            'verification_url' => $verifyUrl,
            'total_amount' => (float) $invoice->total_amount,
            'tax_amount' => (float) $invoice->tax_amount,
            'tax_rate' => (float) ($invoice->tax_rate ?: self::STANDARD_SALES_TAX_RATE),
        ];
    }

    /**
     * Get Pakistan taxation & fiscalization summary for the organization.
     */
    public function getFiscalizationSummary(Organization $organization): array
    {
        $invoices = SalesInvoice::withoutGlobalScopes()
            ->where('organization_id', $organization->id);

        $totalCount = (clone $invoices)->count();
        $fiscalizedInvoices = (clone $invoices)->where('fbr_status', 'fiscalized');
        $fiscalizedCount = $fiscalizedInvoices->count();

        $totalSalesTaxCollected = (float) (clone $fiscalizedInvoices)->sum('tax_amount');
        $totalTaxableSales = (float) (clone $fiscalizedInvoices)->sum('subtotal');
        $totalGrossRevenue = (float) (clone $fiscalizedInvoices)->sum('total_amount');

        return [
            'organization_id' => $organization->id,
            'organization_name' => $organization->name,
            'ntn' => $organization->ntn,
            'strn' => $organization->strn,
            'standard_sales_tax_rate' => self::STANDARD_SALES_TAX_RATE,
            'total_invoices' => $totalCount,
            'fiscalized_invoices' => $fiscalizedCount,
            'pending_invoices' => $totalCount - $fiscalizedCount,
            'total_taxable_sales' => $totalTaxableSales,
            'total_sales_tax_collected' => $totalSalesTaxCollected,
            'total_gross_revenue' => $totalGrossRevenue,
            'currency' => 'PKR',
        ];
    }
}
