<?php

namespace Tests\Feature;

use App\Domain\Accounting\ChartOfAccounts\Templates\PakistanSmeChartTemplate;
use App\Domain\Accounting\Period\Services\PeriodManager;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Services\DocumentStorageService;
use App\Domain\Documents\Services\MalwareScannerService;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Database\Seeders\AccountTypeSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\TestCase;

class DocumentSecurityTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Organization $org;
    private string $token;
    private DocumentStorageService $storageService;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('s3');

        $this->seed(RoleAndPermissionSeeder::class);
        $this->seed(AccountTypeSeeder::class);

        $this->user = User::factory()->create([
            'name' => 'Security Tester',
            'email' => 'security@distributors.pk',
        ]);
        $this->token = $this->user->createToken('test-token')->plainTextToken;

        $this->org = Organization::create([
            'name' => 'Secure Enterprise',
            'legal_name' => 'Secure Enterprise (Pvt) Ltd',
            'base_currency' => 'PKR',
            'fiscal_year_start_month' => 7,
        ]);
        $this->org->users()->attach($this->user->id, ['role' => 'owner', 'is_default' => true]);

        PakistanSmeChartTemplate::seedForOrganization($this->org);
        app(PeriodManager::class)->generateFiscalYear($this->org, 2025);

        $this->storageService = app(DocumentStorageService::class);
    }

    /**
     * P1-26: Strictly reject uploads exceeding 20MB.
     */
    public function test_rejects_files_exceeding_20mb(): void
    {
        // 21 MB fake file
        $file = UploadedFile::fake()->create('large_scan.pdf', 21 * 1024, 'application/pdf');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("exceeds maximum allowed limit of 20MB");

        $this->storageService->store($file, $this->org, $this->user, 'invoice');
    }

    /**
     * P1-26: Magic byte inspection strictly rejects executable binaries disguised as documents.
     */
    public function test_rejects_executable_binaries_spoofed_as_pdf(): void
    {
        // Windows PE executable header MZ disguised with .pdf extension
        $fakeExeContent = "MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00\xff\xff\x00\x00" . str_repeat('A', 100);
        $file = UploadedFile::fake()->createWithContent('invoice.pdf', $fakeExeContent);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Executable binaries are strictly prohibited");

        $this->storageService->store($file, $this->org, $this->user, 'invoice');
    }

    /**
     * P1-26: Magic byte inspection strictly rejects Linux ELF binaries disguised as documents.
     */
    public function test_rejects_linux_elf_binaries_spoofed_as_pdf(): void
    {
        // Linux ELF header \x7fELF disguised with .pdf extension
        $fakeElfContent = "\x7fELF\x02\x01\x01\x00\x00\x00\x00\x00\x00\x00\x00\x00" . str_repeat('B', 100);
        $file = UploadedFile::fake()->createWithContent('contract.pdf', $fakeElfContent);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Executable binaries are strictly prohibited");

        $this->storageService->store($file, $this->org, $this->user, 'contract');
    }

    /**
     * P1-26: Sanitizes filenames against directory traversal attacks.
     */
    public function test_sanitizes_directory_traversal_filenames(): void
    {
        $validPdf = "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF";
        $file = UploadedFile::fake()->createWithContent('../../../etc/passwd_invoice.pdf', $validPdf);

        $doc = $this->storageService->store($file, $this->org, $this->user, 'invoice');

        $this->assertStringNotContainsString('/', $doc->original_filename);
        $this->assertStringNotContainsString('..', $doc->original_filename);
        $this->assertEquals('passwd_invoice.pdf', $doc->original_filename);
    }

    /**
     * P1-27: Malware scanning marks safe files as clean.
     */
    public function test_malware_scanner_marks_clean_files(): void
    {
        $validPdf = "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF";
        $file = UploadedFile::fake()->createWithContent('valid_receipt.pdf', $validPdf);

        $doc = $this->storageService->store($file, $this->org, $this->user, 'receipt');

        $this->assertEquals('clean', $doc->malware_status);
        $this->assertNotNull($doc->malware_scanned_at);
        $this->assertTrue($doc->isMalwareClean());
        $this->assertFalse($doc->isQuarantined());
    }

    /**
     * P1-27: Malware scanning quarantines files containing EICAR test signature.
     */
    public function test_malware_scanner_quarantines_eicar_signature(): void
    {
        $eicarPdf = "%PDF-1.4\nX5O!P%@AP[4\\PZX54(P^)7CC)7}\$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!\$H+H*\n%%EOF";
        $file = UploadedFile::fake()->createWithContent('infected_invoice.pdf', $eicarPdf);

        $doc = $this->storageService->store($file, $this->org, $this->user, 'invoice');

        $this->assertEquals('quarantined', $doc->malware_status);
        $this->assertNotNull($doc->malware_scanned_at);
        $this->assertStringContainsString('EICAR', $doc->malware_scan_notes);
        $this->assertFalse($doc->isMalwareClean());
        $this->assertTrue($doc->isQuarantined());
    }

    /**
     * P1-28: Quarantined documents strictly block temporary signed preview URLs.
     */
    public function test_quarantined_document_blocks_signed_url_generation(): void
    {
        $doc = Document::create([
            'organization_id' => $this->org->id,
            'uploaded_by' => $this->user->id,
            'document_number' => 'DOC-2025-99999',
            'document_type' => 'invoice',
            'original_filename' => 'quarantined.pdf',
            'storage_disk' => 's3',
            'storage_path' => "documents/{$this->org->id}/quarantined.pdf",
            'mime_type' => 'application/pdf',
            'file_size_bytes' => 1024,
            'ocr_status' => 'pending',
            'human_review_status' => 'not_required',
            'malware_status' => 'quarantined',
            'malware_scan_notes' => 'Quarantined due to malicious payload.',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Cannot generate temporary URL for document with malware status: quarantined");

        $doc->getSignedUrl(5);
    }
}
