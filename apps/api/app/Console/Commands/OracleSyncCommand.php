<?php

namespace App\Console\Commands;

use App\Domain\Organization\Models\Organization;
use App\Domain\Purchasing\Models\Vendor;
use App\Domain\Purchasing\Models\PurchaseBill;
use App\Domain\Sales\Models\Customer;
use App\Domain\Sales\Models\SalesInvoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Read-only Phase 1 sync from Oracle Fusion Cloud ERP into a Finova organization.
 * Nothing is ever written to Oracle, and existing Finova records are never deleted.
 */
class OracleSyncCommand extends Command
{
    protected $signature = 'oracle:sync {organization : Finova organization id (use a sandbox org)} {--limit=100} {--dry-run} {--with-invoices : Also import AP bills and AR invoices as drafts}';

    protected $description = 'Import suppliers and customers from Oracle Fusion Cloud ERP (read-only)';

    public function handle(): int
    {
        $cfg = config('services.oracle_erp');
        if (! $cfg['base_url'] || ! $cfg['user'] || ! $cfg['password']) {
            $this->error('Set ORACLE_BASE_URL, ORACLE_USER and ORACLE_PASSWORD in apps/api/.env first.');

            return self::FAILURE;
        }

        $org = Organization::find($this->argument('organization'));
        if (! $org) {
            $this->error('Organization not found.');

            return self::FAILURE;
        }

        $limit = max(1, min(500, (int) $this->option('limit')));
        $dry = (bool) $this->option('dry-run');

        $suppliers = $this->fetch($cfg, '/fscmRestApi/resources/latest/suppliers', $limit);
        $customers = $this->fetch($cfg, '/fscmRestApi/resources/latest/receivablesInvoices', $limit);
        if ($suppliers === null) {
            return self::FAILURE;
        }

        $created = ['vendors' => 0, 'customers' => 0];
        $seen = [];
        foreach ($suppliers as $row) {
            $name = trim((string) ($row['Supplier'] ?? $row['SupplierName'] ?? ''));
            if ($name === '' || Vendor::withoutGlobalScopes()->where('organization_id', $org->id)->where('name', $name)->exists()) {
                continue;
            }
            $created['vendors']++;
            if (! $dry) {
                Vendor::withoutGlobalScopes()->create(['organization_id' => $org->id, 'name' => $name, 'country' => 'PK', 'is_active' => true]);
            }
        }

        foreach ($customers ?? [] as $row) {
            $name = trim((string) ($row['BillToCustomerName'] ?? ''));
            if ($name === '' || isset($seen[$name]) || Customer::withoutGlobalScopes()->where('organization_id', $org->id)->where('name', $name)->exists()) {
                continue;
            }
            $seen[$name] = true;
            $created['customers']++;
            if (! $dry) {
                Customer::withoutGlobalScopes()->create(['organization_id' => $org->id, 'name' => $name, 'country' => 'PK', 'is_active' => true]);
            }
        }

        $this->info(($dry ? '[dry-run] would import ' : 'Imported ')."{$created['vendors']} vendors, {$created['customers']} customers.");

        if ($this->option('with-invoices')) {
            $apRows = $this->fetch($cfg, '/fscmRestApi/resources/latest/invoices', $limit) ?? [];
            [$bills, $invoices] = $this->importDocuments($org, $apRows, $customers ?? [], $dry);
            $this->info(($dry ? '[dry-run] would import ' : 'Imported ')."$bills draft bills, $invoices draft invoices.");
        }

        return self::SUCCESS;
    }

    /** Documents are imported as drafts without journal entries, so the Finova ledger is never touched. */
    private function importDocuments(Organization $org, array $apRows, array $arRows, bool $dry): array
    {
        $bills = 0;
        $invoices = 0;
        $userId = DB::table('organization_user')->where('organization_id', $org->id)->value('user_id');

        foreach ($apRows as $row) {
            $number = 'ORA-'.($row['InvoiceNumber'] ?? '');
            $total = (float) ($row['InvoiceAmount'] ?? 0);
            if ($number === 'ORA-' || $total <= 0 || ($row['CanceledFlag'] ?? false)) {
                continue;
            }
            $vendor = Vendor::withoutGlobalScopes()->where('organization_id', $org->id)->where('name', trim((string) ($row['Supplier'] ?? '')))->first();
            if (! $vendor || PurchaseBill::withoutGlobalScopes()->where('organization_id', $org->id)->where('bill_number', $number)->exists()) {
                continue;
            }
            $bills++;
            if (! $dry) {
                PurchaseBill::withoutGlobalScopes()->create([
                    'organization_id' => $org->id, 'vendor_id' => $vendor->id, 'bill_number' => $number,
                    'vendor_invoice_ref' => $row['InvoiceNumber'], 'bill_date' => $row['InvoiceDate'],
                    'due_date' => $row['DueDate'] ?? $row['InvoiceDate'], 'status' => 'draft', 'currency' => $row['InvoiceCurrency'] ?? 'PKR',
                    'exchange_rate' => 1, 'subtotal' => $total, 'wht_rate' => 0, 'wht_amount' => 0, 'tax_amount' => 0,
                    'total_amount' => $total, 'net_payable' => $total, 'amount_paid' => (float) ($row['AmountPaid'] ?? 0),
                    'notes' => 'Imported from Oracle (draft, not posted to ledger).', 'created_by' => $userId,
                ]);
            }
        }

        foreach ($arRows as $row) {
            $number = 'ORA-'.($row['TransactionNumber'] ?? '');
            $total = (float) ($row['EnteredAmount'] ?? 0);
            if ($number === 'ORA-' || $total <= 0) {
                continue;
            }
            $customer = Customer::withoutGlobalScopes()->where('organization_id', $org->id)->where('name', trim((string) ($row['BillToCustomerName'] ?? '')))->first();
            if (! $customer || SalesInvoice::withoutGlobalScopes()->where('organization_id', $org->id)->where('invoice_number', $number)->exists()) {
                continue;
            }
            $invoices++;
            if (! $dry) {
                SalesInvoice::withoutGlobalScopes()->create([
                    'organization_id' => $org->id, 'customer_id' => $customer->id, 'invoice_number' => $number,
                    'issue_date' => $row['TransactionDate'], 'due_date' => $row['DueDate'] ?? $row['TransactionDate'],
                    'status' => 'draft', 'currency' => $row['InvoiceCurrencyCode'] ?? 'PKR', 'exchange_rate' => 1,
                    'subtotal' => $total, 'tax_rate' => 0, 'tax_amount' => 0, 'total_amount' => $total,
                    'amount_paid' => max(0, $total - (float) ($row['InvoiceBalanceAmount'] ?? $total)),
                    'notes' => 'Imported from Oracle (draft, not posted to ledger).', 'created_by' => $userId,
                ]);
            }
        }

        return [$bills, $invoices];
    }

    private function fetch(array $cfg, string $path, int $limit): ?array
    {
        $response = Http::withBasicAuth($cfg['user'], $cfg['password'])
            ->acceptJson()->timeout(180)
            ->get(rtrim($cfg['base_url'], '/').$path, ['limit' => $limit]);

        if ($response->failed()) {
            $this->warn("Oracle {$path} returned HTTP {$response->status()}.");

            return null;
        }

        return $response->json('items') ?? [];
    }
}
