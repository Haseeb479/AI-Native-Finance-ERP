<?php

namespace App\Console\Commands;

use App\Domain\Operations\Services\BackupVerificationService;
use Illuminate\Console\Command;

class VerifyBackupCommand extends Command
{
    protected $signature = 'backup:verify {--rpo=24 : Maximum allowable backup age in hours} {--create-snapshot : Create a verifiable backup snapshot prior to verification}';

    protected $description = 'Verify database backup existence, SHA-256 cryptographic integrity, and RPO/RTO compliance (P1-36)';

    public function handle(BackupVerificationService $service): int
    {
        if ($this->option('create-snapshot')) {
            $this->info('Creating fresh backup snapshot...');
            $manifest = $service->createBackupSnapshot('scheduled_cli');
            $this->info("Snapshot created: {$manifest['filename']} (SHA256: {$manifest['sha256']})");
        }

        $rpoHours = (int) $this->option('rpo');
        $this->info("Verifying latest backup against RPO target ({$rpoHours}h)...");

        $result = $service->verifyLatestBackup($rpoHours);

        if (! $result['pass']) {
            $this->error("Backup verification FAILED!");
            $this->table(
                ['Check', 'Result', 'Details'],
                [
                    ['Status', $result['status'], $result['error'] ?? 'Check failed'],
                    ['RPO Compliant', ($result['rpo_compliant'] ?? false) ? 'YES' : 'NO', 'Target: ' . $rpoHours . 'h'],
                    ['Integrity Valid', ($result['integrity_valid'] ?? false) ? 'YES' : 'NO', 'SHA-256 mismatch or file missing'],
                ]
            );
            return 1;
        }

        $this->info("Backup verification PASSED! Status: {$result['status']}");
        $this->table(
            ['Metric', 'Value'],
            [
                ['Latest Backup', $result['latest_backup']['filename']],
                ['Backup Age', "{$result['latest_backup']['age_hours']} hours (Target: <= {$rpoHours}h)"],
                ['Size', number_format($result['latest_backup']['size_bytes']) . ' bytes'],
                ['SHA-256 Integrity', $result['integrity']['valid'] ? 'VALID (Matches manifest)' : 'CORRUPT'],
                ['Estimated RTO', "{$result['rto']['estimated_minutes']} minutes (Target: <= {$result['rto']['target_minutes']}m)"],
            ]
        );

        return 0;
    }
}
