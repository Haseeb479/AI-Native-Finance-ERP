<?php

namespace App\Console\Commands;

use App\Domain\Organization\Models\Organization;
use App\Domain\Purchasing\Models\Vendor;
use App\Domain\Sales\Models\Customer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Read-only Phase 1 sync from Oracle Fusion Cloud ERP into a Finova organization.
 * Nothing is ever written to Oracle, and existing Finova records are never deleted.
 */
class OracleSyncCommand extends Command
{
    protected $signature = 'oracle:sync {organization : Finova organization id (use a sandbox org)} {--limit=100} {--dry-run}';

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

        return self::SUCCESS;
    }

    private function fetch(array $cfg, string $path, int $limit): ?array
    {
        $response = Http::withBasicAuth($cfg['user'], $cfg['password'])
            ->acceptJson()->timeout(30)
            ->get(rtrim($cfg['base_url'], '/').$path, ['limit' => $limit]);

        if ($response->failed()) {
            $this->warn("Oracle {$path} returned HTTP {$response->status()}.");

            return null;
        }

        return $response->json('items') ?? [];
    }
}
