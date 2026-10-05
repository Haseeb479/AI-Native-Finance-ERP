<?php

namespace App\Domain\Operations\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PDO;
use Symfony\Component\Process\Process;
use RuntimeException;

class BackupVerificationService
{
    protected string $backupDir;
    protected int $targetRpoHours = 24;
    protected int $targetRtoMinutes = 60;

    public function __construct(?string $backupDir = null)
    {
        $this->backupDir = $backupDir ?? storage_path('app/backups');
        if (! File::exists($this->backupDir)) {
            File::makeDirectory($this->backupDir, 0755, true, true);
        }
    }

    /**
     * Create an audited backup snapshot with cryptographic SHA-256 manifest.
     */
    public function createBackupSnapshot(string $label = 'scheduled'): array
    {
        $label = preg_replace('/[^A-Za-z0-9_-]/', '_', substr($label, 0, 48)) ?: 'scheduled';
        $timestamp = now()->format('Y-m-d_H-i-s');
        $driver = config('database.default');
        $extension = $driver === 'pgsql' ? 'dump' : 'json';
        $filename = "backup_{$label}_{$timestamp}_" . bin2hex(random_bytes(4)) . ".{$extension}";
        $filepath = "{$this->backupDir}/{$filename}";
        $remoteDisk = config('backup.remote_disk');

        if (app()->isProduction() && (! is_string($remoteDisk) || $remoteDisk === '' || $remoteDisk === 'local')) {
            throw new RuntimeException('Production database backups require a configured off-host remote disk.');
        }

        if ($driver === 'pgsql') {
            $this->createPostgresDump($filepath);
        } elseif ($driver === 'sqlite' && app()->environment('testing')) {
            $this->createSqliteTestSnapshot($filepath);
        } else {
            throw new RuntimeException("Database backup is not supported for driver '{$driver}'.");
        }

        $sha256 = hash_file('sha256', $filepath);
        if ($sha256 === false) {
            throw new RuntimeException("Unable to calculate backup checksum for {$filename}.");
        }

        $manifest = [
            'filename' => $filename,
            'filepath' => $filepath,
            'size_bytes' => filesize($filepath),
            'sha256' => $sha256,
            'created_at' => now()->toIso8601String(),
            'format' => $driver === 'pgsql' ? 'postgres_custom_dump' : 'sqlite_test_snapshot',
            'rpo_target_hours' => $this->targetRpoHours,
            'rto_target_minutes' => $this->targetRtoMinutes,
        ];

        if (is_string($remoteDisk) && $remoteDisk !== '' && $remoteDisk !== 'local') {
            $manifest['remote_key'] = "database-backups/{$filename}";
        }

        $manifestPath = "{$filepath}.manifest.json";
        $manifestJson = json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        if (! File::put($manifestPath, $manifestJson)) {
            throw new RuntimeException("Unable to write backup manifest for {$filename}.");
        }

        if (isset($manifest['remote_key'])) {
            $disk = Storage::disk($remoteDisk);
            $stream = fopen($filepath, 'rb');
            if ($stream === false) {
                throw new RuntimeException("Unable to open backup archive {$filename} for remote upload.");
            }

            try {
                if (! $disk->put($manifest['remote_key'], $stream, ['ServerSideEncryption' => 'AES256'])) {
                    throw new RuntimeException("Remote upload failed for backup {$filename}.");
                }
            } finally {
                fclose($stream);
            }

            if (! $disk->put("{$manifest['remote_key']}.manifest.json", $manifestJson, ['ServerSideEncryption' => 'AES256'])) {
                throw new RuntimeException("Remote manifest upload failed for backup {$filename}.");
            }
        }

        Log::info("Backup snapshot created successfully", [
            'filename' => $filename,
            'sha256' => $sha256,
            'size_bytes' => $manifest['size_bytes'],
            'remote_key' => $manifest['remote_key'] ?? null,
        ]);

        return $manifest;
    }

    /**
     * Verify backup integrity, cryptographic checksum, and RPO compliance.
     * P1-36: Automated backups, restore tests, RPO, RTO, integrity verification.
     */
    public function verifyLatestBackup(int $maxRpoHours = 24): array
    {
        $manifestFiles = glob("{$this->backupDir}/*.manifest.json");

        if (empty($manifestFiles)) {
            return [
                'pass' => false,
                'status' => 'missing',
                'error' => 'No backup manifests found in backup directory.',
                'rpo_compliant' => false,
                'integrity_valid' => false,
                'rto_estimate_minutes' => null,
            ];
        }

        // Sort descending by modified time
        usort($manifestFiles, fn($a, $b) => filemtime($b) <=> filemtime($a));
        $latestManifestPath = $manifestFiles[0];

        $manifestContent = json_decode(File::get($latestManifestPath), true);
        if (
            ! is_array($manifestContent)
            || empty($manifestContent['filename'])
            || basename($manifestContent['filename']) !== $manifestContent['filename']
            || empty($manifestContent['sha256'])
            || ! preg_match('/\A[a-f0-9]{64}\z/i', $manifestContent['sha256'])
        ) {
            return [
                'pass' => false,
                'status' => 'corrupt_manifest',
                'error' => 'Backup manifest is corrupt or unreadable.',
                'rpo_compliant' => false,
                'integrity_valid' => false,
                'rto_estimate_minutes' => null,
            ];
        }

        $backupFilePath = "{$this->backupDir}/{$manifestContent['filename']}";
        if (! File::exists($backupFilePath)) {
            return [
                'pass' => false,
                'status' => 'missing_archive',
                'error' => "Backup archive file not found: {$backupFilePath}",
                'rpo_compliant' => false,
                'integrity_valid' => false,
                'rto_estimate_minutes' => null,
            ];
        }

        // 1. Verify RPO (Recovery Point Objective)
        $backupAgeSeconds = time() - filemtime($backupFilePath);
        $backupAgeHours = round($backupAgeSeconds / 3600, 2);
        $rpoCompliant = $backupAgeHours <= $maxRpoHours;

        // 2. Verify Cryptographic Integrity (SHA-256)
        $actualSha256 = hash_file('sha256', $backupFilePath);
        if ($actualSha256 === false) {
            return [
                'pass' => false,
                'status' => 'unreadable_archive',
                'error' => 'Backup archive could not be read for integrity verification.',
                'rpo_compliant' => false,
                'integrity_valid' => false,
                'rto_estimate_minutes' => null,
            ];
        }
        $integrityValid = hash_equals($manifestContent['sha256'], $actualSha256);

        // 3. Benchmarked RTO (Recovery Time Objective) Estimation
        $fileSizeBytes = filesize($backupFilePath);
        // Estimated restore throughput: 25MB/sec
        $estimatedRestoreSeconds = max(5, ceil($fileSizeBytes / (25 * 1024 * 1024)));
        $rtoEstimateMinutes = round($estimatedRestoreSeconds / 60, 2);
        $rtoCompliant = $rtoEstimateMinutes <= $this->targetRtoMinutes;

        $remoteKey = $manifestContent['remote_key'] ?? null;
        $remoteIntegrityValid = true;
        if (app()->isProduction() && empty($remoteKey)) {
            $remoteIntegrityValid = false;
        } elseif (is_string($remoteKey) && $remoteKey !== '') {
            $remoteDisk = config('backup.remote_disk');
            if (! is_string($remoteDisk) || $remoteDisk === '' || ! Storage::disk($remoteDisk)->exists($remoteKey)) {
                $remoteIntegrityValid = false;
            } else {
                $remoteStream = Storage::disk($remoteDisk)->readStream($remoteKey);
                if (! is_resource($remoteStream)) {
                    $remoteIntegrityValid = false;
                } else {
                    $remoteHash = hash_init('sha256');
                    hash_update_stream($remoteHash, $remoteStream);
                    fclose($remoteStream);
                    $remoteIntegrityValid = hash_equals($manifestContent['sha256'], hash_final($remoteHash));
                }
            }
        }
        $integrityValid = $integrityValid && $remoteIntegrityValid;
        $overallPass = $rpoCompliant && $integrityValid && $rtoCompliant;

        return [
            'pass' => $overallPass,
            'status' => $overallPass ? 'verified' : 'failed',
            'latest_backup' => [
                'filename' => $manifestContent['filename'],
                'age_hours' => $backupAgeHours,
                'size_bytes' => $fileSizeBytes,
                'sha256_match' => $integrityValid,
            ],
            'rpo' => [
                'target_hours' => $maxRpoHours,
                'current_age_hours' => $backupAgeHours,
                'compliant' => $rpoCompliant,
            ],
            'rto' => [
                'target_minutes' => $this->targetRtoMinutes,
                'estimated_minutes' => $rtoEstimateMinutes,
                'compliant' => $rtoCompliant,
            ],
            'integrity' => [
                'algorithm' => 'SHA-256',
                'expected_hash' => $manifestContent['sha256'],
                'actual_hash' => $actualSha256,
                'valid' => $integrityValid,
            ],
        ];
    }

    /**
     * Execute a repeatable restore drill to verify that database backups
     * can be reconstructed and pass financial integrity and invariant checks.
     * P0-06 / P1-36: Real restore drill with accounting integrity validation.
     */
    public function executeRestoreDrill(?string $backupFilePath = null): array
    {
        $startTime = microtime(true);

        if (! $backupFilePath) {
            $manifestFiles = glob("{$this->backupDir}/*.manifest.json");
            if (empty($manifestFiles)) {
                return [
                    'pass' => false,
                    'status' => 'missing_manifest',
                    'error' => 'No backup manifests found to execute restore drill.',
                ];
            }
            usort($manifestFiles, fn($a, $b) => filemtime($b) <=> filemtime($a));
            $manifestContent = json_decode(File::get($manifestFiles[0]), true);
            $filename = $manifestContent['filename'] ?? null;
            $backupFilePath = is_string($filename) && basename($filename) === $filename
                ? "{$this->backupDir}/{$filename}"
                : null;
        }

        if (! $backupFilePath || ! File::exists($backupFilePath)) {
            return [
                'pass' => false,
                'status' => 'missing_archive',
                'error' => "Backup file does not exist: {$backupFilePath}",
            ];
        }

        // 1. Check SHA-256 cryptographic integrity before unpacking
        $actualSha256 = hash_file('sha256', $backupFilePath);
        $manifestPath = "{$backupFilePath}.manifest.json";
        if (! File::exists($manifestPath)) {
            return [
                'pass' => false,
                'status' => 'missing_manifest',
                'error' => 'Backup manifest is required for a restore drill.',
            ];
        }
        $manifest = json_decode(File::get($manifestPath), true);
        if (! is_array($manifest) || ! is_string($actualSha256) || ! hash_equals($manifest['sha256'] ?? '', $actualSha256)) {
            return [
                'pass' => false,
                'status' => 'corrupt_checksum',
                'error' => 'Cryptographic checksum mismatch on restore drill.',
            ];
        }

        $driver = config('database.default');
        $restoreResult = match ($driver) {
            'pgsql' => $this->restorePostgresDump($backupFilePath),
            'sqlite' => $this->restoreSqliteTestSnapshot($backupFilePath),
            default => throw new RuntimeException("Restore drill is not supported for database driver '{$driver}'."),
        };

        $durationMs = round((microtime(true) - $startTime) * 1000, 2);
        $rtoCertified = ($durationMs / 60000) <= $this->targetRtoMinutes;

        return [
            'pass' => $rtoCertified,
            'status' => $rtoCertified ? 'drill_verified' : 'rto_exceeded',
            'filename' => basename($backupFilePath),
            'tables_verified' => $restoreResult['tables_verified'],
            'rows_verified' => $restoreResult['rows_verified'],
            'accounting_invariants_verified' => $restoreResult['accounting_invariants_verified'],
            'tenant_isolation_verified' => $restoreResult['tenant_isolation_verified'],
            'duration_ms' => $durationMs,
            'rto_certified' => $rtoCertified,
            'timestamp' => now()->toIso8601String(),
        ];
    }

    private function createPostgresDump(string $filepath): void
    {
        $connection = config('database.connections.pgsql');
        $arguments = [
            config('backup.pg_dump_binary', 'pg_dump'),
            '--format=custom',
            '--no-owner',
            '--no-privileges',
            "--file={$filepath}",
            "--dbname={$connection['database']}",
            "--username={$connection['username']}",
        ];
        if (! empty($connection['host'])) {
            $arguments[] = "--host={$connection['host']}";
        }
        if (! empty($connection['port'])) {
            $arguments[] = "--port={$connection['port']}";
        }

        $process = new Process($arguments, null, ['PGPASSWORD' => $connection['password'] ?? '']);
        $process->setTimeout(3600);
        $process->run();
        if (! $process->isSuccessful()) {
            throw new RuntimeException('pg_dump failed: ' . trim($process->getErrorOutput()));
        }
    }

    private function createSqliteTestSnapshot(string $filepath): void
    {
        $schema = DB::select("SELECT type, name, sql FROM sqlite_master WHERE sql IS NOT NULL AND name NOT LIKE 'sqlite_%' ORDER BY CASE type WHEN 'table' THEN 0 ELSE 1 END");
        $tables = DB::select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'");
        $rows = [];

        foreach ($tables as $table) {
            $rows[$table->name] = DB::table($table->name)->get()->map(fn ($row) => (array) $row)->all();
        }

        $snapshot = json_encode([
            'format' => 'sqlite-test-snapshot',
            'schema' => $schema,
            'rows' => $rows,
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        if (! File::put($filepath, $snapshot)) {
            throw new RuntimeException('Unable to write the SQLite test snapshot.');
        }
    }

    private function restoreSqliteTestSnapshot(string $filepath): array
    {
        $snapshot = json_decode(File::get($filepath), true);
        if (
            ! is_array($snapshot)
            || ($snapshot['format'] ?? null) !== 'sqlite-test-snapshot'
            || ! is_array($snapshot['schema'] ?? null)
            || ! is_array($snapshot['rows'] ?? null)
        ) {
            throw new RuntimeException('SQLite test backup has an invalid snapshot format.');
        }

        $restorePath = "{$this->backupDir}/restore_drill_" . bin2hex(random_bytes(8)) . '.sqlite';
        $restored = new PDO("sqlite:{$restorePath}");
        $restored->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $restored->exec('PRAGMA foreign_keys = OFF');

        try {
            foreach ($snapshot['schema'] as $object) {
                if (($object['type'] ?? null) === 'table') {
                    $restored->exec($object['sql']);
                }
            }

            foreach ($snapshot['rows'] as $table => $tableRows) {
                if (! preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $table)) {
                    throw new RuntimeException('SQLite test backup contains an invalid table name.');
                }

                foreach ($tableRows as $row) {
                    if (! is_array($row) || $row === []) {
                        continue;
                    }
                    $columns = array_keys($row);
                    foreach ($columns as $column) {
                        if (! preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $column)) {
                            throw new RuntimeException('SQLite test backup contains an invalid column name.');
                        }
                    }

                    $quotedColumns = implode(', ', array_map(fn ($column) => "\"{$column}\"", $columns));
                    $placeholders = implode(', ', array_fill(0, count($columns), '?'));
                    $statement = $restored->prepare("INSERT INTO \"{$table}\" ({$quotedColumns}) VALUES ({$placeholders})");
                    $statement->execute(array_values($row));
                }
            }

            foreach ($snapshot['schema'] as $object) {
                if (($object['type'] ?? null) !== 'table') {
                    $restored->exec($object['sql']);
                }
            }

            $integrity = $restored->query('PRAGMA integrity_check')->fetchColumn();
            if ($integrity !== 'ok' || $restored->query('PRAGMA foreign_key_check')->fetch() !== false) {
                throw new RuntimeException('Restored SQLite test database failed integrity or foreign-key checks.');
            }

            return $this->validateRestoredLedger($restored, 'sqlite');
        } finally {
            $restored = null;
            File::delete($restorePath);
        }
    }

    private function restorePostgresDump(string $filepath): array
    {
        $restore = config('backup.restore');
        if (
            empty($restore['host'])
            || empty($restore['port'])
            || empty($restore['username'])
            || empty($restore['password'])
            || empty($restore['maintenance_database'])
        ) {
            throw new RuntimeException('PostgreSQL restore-drill credentials are incomplete.');
        }
        $password = $restore['password'] ?? '';
        $commonArguments = [];
        foreach (['host', 'port', 'username'] as $field) {
            if (! empty($restore[$field])) {
                $option = ['host' => '--host', 'port' => '--port', 'username' => '--username'][$field];
                $commonArguments[] = $option;
                $commonArguments[] = (string) $restore[$field];
            }
        }

        $listing = new Process([config('backup.pg_restore_binary', 'pg_restore'), '--list', $filepath]);
        $listing->setTimeout(300);
        $listing->run();
        if (! $listing->isSuccessful()) {
            throw new RuntimeException('pg_restore could not read the backup archive: ' . trim($listing->getErrorOutput()));
        }

        $temporaryDatabase = 'finance_restore_drill_' . bin2hex(random_bytes(6));
        $created = false;
        $environment = ['PGPASSWORD' => $password];

        try {
            $create = new Process(array_merge(
                [config('backup.createdb_binary', 'createdb')],
                $commonArguments,
                ['--maintenance-db', $restore['maintenance_database'], '--template', 'template0', $temporaryDatabase]
            ), null, $environment);
            $create->setTimeout(300);
            $create->run();
            if (! $create->isSuccessful()) {
                throw new RuntimeException('Could not create isolated PostgreSQL restore database: ' . trim($create->getErrorOutput()));
            }
            $created = true;

            $restoreProcess = new Process(array_merge(
                [config('backup.pg_restore_binary', 'pg_restore'), '--exit-on-error', '--no-owner', '--no-privileges'],
                $commonArguments,
                ['--dbname', $temporaryDatabase, $filepath]
            ), null, $environment);
            $restoreProcess->setTimeout(3600);
            $restoreProcess->run();
            if (! $restoreProcess->isSuccessful()) {
                throw new RuntimeException('pg_restore failed: ' . trim($restoreProcess->getErrorOutput()));
            }

            $dsn = sprintf(
                'pgsql:host=%s;port=%s;dbname=%s',
                $restore['host'],
                $restore['port'],
                $temporaryDatabase
            );
            $pdo = new PDO($dsn, $restore['username'], $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

            return $this->validateRestoredLedger($pdo, 'pgsql');
        } finally {
            $drop = new Process(array_merge(
                [config('backup.dropdb_binary', 'dropdb'), '--if-exists'],
                $commonArguments,
                ['--maintenance-db', $restore['maintenance_database'], $temporaryDatabase]
            ), null, $environment);
            $drop->setTimeout(300);
            $drop->run();
            if (! $drop->isSuccessful()) {
                throw new RuntimeException('Could not remove isolated PostgreSQL restore database: ' . trim($drop->getErrorOutput()));
            }
        }
    }

    private function validateRestoredLedger(PDO $pdo, string $driver): array
    {
        $tableQuery = $driver === 'pgsql'
            ? "SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' AND table_type = 'BASE TABLE'"
            : "SELECT name AS table_name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'";
        $tables = $pdo->query($tableQuery)->fetchAll(PDO::FETCH_COLUMN);
        $rowsVerified = 0;
        foreach ($tables as $table) {
            if (! preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $table)) {
                throw new RuntimeException('Restored database contains an unexpected table identifier.');
            }
            $quotedTable = $driver === 'pgsql' ? "\"public\".\"{$table}\"" : "\"{$table}\"";
            $rowsVerified += (int) $pdo->query("SELECT COUNT(*) FROM {$quotedTable}")->fetchColumn();
        }

        $requiredTables = ['organizations', 'users', 'accounts', 'journal_entries', 'journal_lines'];
        $missingTables = array_diff($requiredTables, $tables);
        if ($missingTables !== []) {
            throw new RuntimeException(
                'Restored database is missing critical tables: ' . implode(', ', $missingTables)
                . '. Tables found: ' . implode(', ', $tables)
            );
        }

        $unbalancedJournals = (int) $pdo->query(
            "SELECT COUNT(*) FROM (
                SELECT je.id
                FROM journal_entries je
                LEFT JOIN journal_lines jl ON jl.journal_entry_id = je.id
                WHERE je.status = 'posted'
                GROUP BY je.id
                HAVING COALESCE(SUM(jl.debit), 0) <> COALESCE(SUM(jl.credit), 0)
            ) unbalanced"
        )->fetchColumn();
        $crossTenantLines = (int) $pdo->query(
            'SELECT COUNT(*) FROM journal_lines jl JOIN journal_entries je ON je.id = jl.journal_entry_id WHERE jl.organization_id <> je.organization_id'
        )->fetchColumn();

        if ($unbalancedJournals !== 0 || $crossTenantLines !== 0) {
            throw new RuntimeException('Restored database failed posted-journal balance or tenant consistency checks.');
        }

        return [
            'tables_verified' => count($tables),
            'rows_verified' => $rowsVerified,
            'accounting_invariants_verified' => true,
            'tenant_isolation_verified' => true,
        ];
    }
}
