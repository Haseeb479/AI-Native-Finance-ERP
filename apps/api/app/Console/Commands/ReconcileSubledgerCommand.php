<?php

namespace App\Console\Commands;

use App\Domain\Accounting\Reconciliation\Services\SubledgerReconciliationService;
use App\Domain\Organization\Models\Organization;
use Illuminate\Console\Command;

class ReconcileSubledgerCommand extends Command
{
    protected $signature = 'reconciliation:subledger {organization_id? : Target organization ID}';
    protected $description = 'Run automated subledger to GL reconciliation checks for AR, AP, and Bank accounts';

    public function handle(SubledgerReconciliationService $service): int
    {
        $orgId = $this->argument('organization_id');
        $orgs = $orgId
            ? Organization::where('id', $orgId)->get()
            : Organization::all();

        if ($orgs->isEmpty()) {
            $this->warn('No organizations found to reconcile.');
            return 0;
        }

        $this->info("Running automated Subledger to GL reconciliation across {$orgs->count()} organization(s)...");

        $overallExitCode = 0;

        foreach ($orgs as $org) {
            $this->line("--------------------------------------------------");
            $this->info("Organization: {$org->name} ({$org->id})");

            $report = $service->runFullReconciliation($org);

            $rows = [];
            foreach ($report['reconciliations'] as $type => $rec) {
                $statusColor = $rec['status'] === 'reconciled' ? 'info' : 'error';
                $rows[] = [
                    $rec['subledger_type'],
                    $rec['control_account_code'],
                    $rec['subledger_balance'],
                    $rec['gl_balance'],
                    $rec['variance'],
                    $rec['status'],
                ];

                if ($rec['status'] !== 'reconciled') {
                    $overallExitCode = 1;
                }
            }

            $this->table(
                ['Subledger', 'GL Code', 'Subledger Balance', 'GL Balance', 'Variance', 'Status'],
                $rows
            );
        }

        return $overallExitCode;
    }
}
