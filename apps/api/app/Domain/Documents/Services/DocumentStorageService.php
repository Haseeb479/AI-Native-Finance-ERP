<?php

namespace App\Domain\Documents\Services;

use App\Domain\Documents\Models\Document;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class DocumentStorageService
{
    /**
     * Store an uploaded file privately in object storage (MinIO/S3).
     * Files are stored at: documents/{organization_id}/{uuid}.{extension}
     * The storage path is NEVER returned directly to API consumers — use getSignedUrl() instead.
     */
    public function store(
        UploadedFile $file,
        Organization $org,
        User $user,
        string $documentType = 'other'
    ): Document {
        $documentId = (string) \Illuminate\Support\Str::uuid();
        $extension = strtolower($file->getClientOriginalExtension());
        $storagePath = "documents/{$org->id}/{$documentId}.{$extension}";
        $disk = config('filesystems.default', 's3');

        // Store privately — no public visibility
        Storage::disk($disk)->put($storagePath, file_get_contents($file->getRealPath()), [
            'visibility' => 'private',
            'ContentType' => $file->getMimeType(),
        ]);

        $documentNumber = $this->generateDocumentNumber($org);

        return Document::create([
            'id' => $documentId,
            'organization_id' => $org->id,
            'uploaded_by' => $user->id,
            'document_number' => $documentNumber,
            'document_type' => $documentType,
            'original_filename' => $file->getClientOriginalName(),
            'storage_disk' => $disk,
            'storage_path' => $storagePath,
            'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
            'file_size_bytes' => $file->getSize(),
            'ocr_status' => 'pending',
            'human_review_status' => 'not_required',
        ]);
    }

    /**
     * Generate a 5-minute signed URL for temporary document preview.
     * Never return the raw storage_path.
     */
    public function getSignedUrl(Document $doc, int $expiresInMinutes = 5): ?string
    {
        return $doc->getSignedUrl($expiresInMinutes);
    }

    /**
     * Permanently remove the file from storage and soft-delete the record.
     */
    public function delete(Document $doc): void
    {
        try {
            Storage::disk($doc->storage_disk)->delete($doc->storage_path);
        } catch (\Exception $e) {
            // Log but do not block deletion of the DB record
            \Illuminate\Support\Facades\Log::warning("Could not delete file from storage: {$doc->storage_path}", [
                'error' => $e->getMessage(),
            ]);
        }

        $doc->delete();
    }

    private function generateDocumentNumber(Organization $org): string
    {
        $year = now()->format('Y');
        $prefix = "DOC-{$year}-";

        $last = Document::withoutGlobalScopes()
            ->where('organization_id', $org->id)
            ->where('document_number', 'LIKE', "{$prefix}%")
            ->orderBy('document_number', 'desc')
            ->first();

        $next = $last ? ((int) substr($last->document_number, strlen($prefix)) + 1) : 1;

        return $prefix . str_pad((string) $next, 5, '0', STR_PAD_LEFT);
    }
}
