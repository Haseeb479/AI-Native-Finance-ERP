<?php

namespace Tests\Feature;

use App\Domain\Operations\Services\BackupVerificationService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class BackupVerificationTest extends TestCase
{
    protected string $testBackupDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->testBackupDir = storage_path('framework/testing/backups_' . uniqid());
    }

    protected function tearDown(): void
    {
        if (File::exists($this->testBackupDir)) {
            File::deleteDirectory($this->testBackupDir);
        }
        parent::tearDown();
    }

    public function test_backup_snapshot_creation_and_integrity_verification(): void
    {
        $service = new BackupVerificationService($this->testBackupDir);
        $manifest = $service->createBackupSnapshot('unit_test');

        $this->assertFileExists($manifest['filepath']);
        $this->assertFileExists($manifest['filepath'] . '.manifest.json');
        $this->assertEquals(64, strlen($manifest['sha256']));

        // Verify with RPO 24 hours
        $verification = $service->verifyLatestBackup(24);

        $this->assertTrue($verification['pass']);
        $this->assertEquals('verified', $verification['status']);
        $this->assertTrue($verification['rpo']['compliant']);
        $this->assertTrue($verification['integrity']['valid']);
        $this->assertEquals($manifest['sha256'], $verification['integrity']['actual_hash']);
    }

    public function test_backup_verification_detects_tampered_or_corrupt_archive(): void
    {
        $service = new BackupVerificationService($this->testBackupDir);
        $manifest = $service->createBackupSnapshot('tamper_test');

        // Tamper with the backup file by appending corrupt data
        File::append($manifest['filepath'], "\n-- CORRUPTED BY ADVERSARY --\n");

        $verification = $service->verifyLatestBackup(24);

        $this->assertFalse($verification['pass']);
        $this->assertEquals('failed', $verification['status']);
        $this->assertFalse($verification['integrity']['valid']);
        $this->assertNotEquals(
            $verification['integrity']['expected_hash'],
            $verification['integrity']['actual_hash']
        );
    }

    public function test_artisan_backup_verify_command(): void
    {
        $this->artisan('backup:verify --create-snapshot --rpo=24')
            ->assertExitCode(0);
    }

    public function test_restore_drill_passes_and_certifies_rto(): void
    {
        $service = new BackupVerificationService($this->testBackupDir);
        $service->createBackupSnapshot('restore_test');

        $drillResult = $service->executeRestoreDrill();

        $this->assertTrue($drillResult['pass']);
        $this->assertEquals('drill_verified', $drillResult['status']);
        $this->assertTrue($drillResult['accounting_invariants_verified']);
        $this->assertTrue($drillResult['tenant_isolation_verified']);
        $this->assertTrue($drillResult['rto_certified']);
        $this->assertGreaterThan(0, $drillResult['tables_verified']);
    }

    public function test_restore_drill_fails_on_corrupted_backup(): void
    {
        $service = new BackupVerificationService($this->testBackupDir);
        $manifest = $service->createBackupSnapshot('corrupt_drill_test');

        // Corrupt file
        File::append($manifest['filepath'], 'CORRUPT_BYTES');

        $drillResult = $service->executeRestoreDrill();

        $this->assertFalse($drillResult['pass']);
        $this->assertEquals('corrupt_checksum', $drillResult['status']);
    }

    public function test_artisan_backup_verify_with_restore_drill_command(): void
    {
        $this->artisan('backup:verify --create-snapshot --restore-drill --rpo=24')
            ->assertExitCode(0);
    }
}
