<?php

namespace App\Domain\Documents\Services;

use App\Domain\Documents\Jobs\OcrExtractJob;
use App\Domain\Documents\Models\Document;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;

class DocumentService
{
    public function __construct(
        protected DocumentStorageService $storageService,
        protected OcrService $ocrService,
    ) {}

    /**
     * Upload a document, store it privately, and dispatch async OCR.
     */
    public function upload(
        UploadedFile $file,
        Organization $org,
        string $documentType,
        User $user,
        bool $autoOcr = true
    ): Document {
        $document = $this->storageService->store($file, $org, $user, $documentType);

        if ($autoOcr) {
            OcrExtractJob::dispatch($document->id, $user->id);
        }

        return $document;
    }

    /**
     * Approve a document's extraction, optionally overriding fields with human corrections.
     * Immutable audit trail: stores who reviewed, when, and any corrections applied.
     */
    public function approveExtraction(Document $doc, array $correctedData, User $user): Document
    {
        if (!$doc->isOcrComplete() && $doc->ocr_status !== 'failed') {
            throw new InvalidArgumentException('Cannot approve a document that has not been through OCR processing.');
        }

        $finalData = !empty($correctedData)
            ? array_merge($doc->extracted_data ?? [], $correctedData)
            : ($doc->extracted_data ?? []);

        $doc->update([
            'extracted_data' => $finalData,
            'human_review_status' => 'approved',
            'human_reviewed_by' => $user->id,
            'human_reviewed_at' => now(),
            'human_review_notes' => 'Human approved' . (!empty($correctedData) ? ' with corrections.' : '.'),
        ]);

        return $doc->fresh();
    }

    /**
     * Reject a document's extraction (e.g., illegible scan, wrong document type).
     */
    public function rejectExtraction(Document $doc, string $notes, User $user): Document
    {
        $doc->update([
            'human_review_status' => 'rejected',
            'human_reviewed_by' => $user->id,
            'human_reviewed_at' => now(),
            'human_review_notes' => $notes,
        ]);

        return $doc->fresh();
    }
}
