<?php

namespace App\Domain\Documents\Services;

use App\Domain\Documents\Models\Document;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OcrService
{
    /**
     * Confidence threshold below which human review is automatically required.
     */
    private const HUMAN_REVIEW_THRESHOLD = 0.70;

    /**
     * AI service base URL. Falls back to mock if not set.
     */
    private string $aiServiceUrl;

    public function __construct()
    {
        $this->aiServiceUrl = config('services.ai.url', 'http://localhost:8001');
    }

    /**
     * Extract structured data from a document using the AI service.
     *
     * Follows RULES.md Rule 5: all text content from uploaded documents is treated
     * as untrusted input. The Python AI service wraps text through sanitize_untrusted_document_text()
     * before passing to any LLM.
     *
     * AI may NEVER mutate financial records — it only extracts draft suggestions.
     */
    public function extractFromDocument(Document $doc, ?User $user = null): Document
    {
        $doc->update(['ocr_status' => 'processing']);

        try {
            // Determine the right extraction endpoint based on document type
            $endpoint = match ($doc->document_type) {
                'invoice' => '/api/v1/extract/invoice',
                'receipt' => '/api/v1/extract/receipt',
                default   => '/api/v1/extract/invoice', // fallback to invoice parser
            };

            // Fetch raw text from storage to pass to the AI service
            // (the AI service does NOT have direct storage access — we pass text content)
            $rawText = $this->getRawTextFromDocument($doc);

            if (empty(trim($rawText))) {
                $doc->update([
                    'ocr_status' => 'skipped',
                    'human_review_status' => 'pending',
                ]);
                return $doc;
            }

            // Call the Python AI extraction service
            $response = Http::timeout(30)->post("{$this->aiServiceUrl}{$endpoint}", [
                'raw_document_text' => $rawText,
                'organization_id' => $doc->organization_id,
            ]);

            if (!$response->successful()) {
                throw new \RuntimeException("AI extraction service returned HTTP {$response->status()}");
            }

            $result = $response->json();
            $confidence = (float) ($result['extraction_confidence'] ?? 0.0);
            $wasFlagged = (bool) ($result['flagged_for_review'] ?? false);

            // Determine if human review is required
            $needsHumanReview = $wasFlagged || $confidence < self::HUMAN_REVIEW_THRESHOLD;

            $doc->update([
                'ocr_status' => 'completed',
                'ocr_raw_text' => $rawText,
                'ocr_was_flagged' => $wasFlagged,
                'extracted_data' => $result,
                'extraction_confidence' => $confidence,
                'human_review_status' => $needsHumanReview ? 'pending' : 'not_required',
            ]);

            Log::info('Document OCR completed', [
                'document_id' => $doc->id,
                'organization_id' => $doc->organization_id,
                'document_type' => $doc->document_type,
                'confidence' => $confidence,
                'was_flagged' => $wasFlagged,
                'needs_human_review' => $needsHumanReview,
                'user_id' => $user?->id,
            ]);

        } catch (\Exception $e) {
            Log::error('Document OCR failed', [
                'document_id' => $doc->id,
                'organization_id' => $doc->organization_id,
                'error' => $e->getMessage(),
            ]);

            $doc->update([
                'ocr_status' => 'failed',
                'human_review_status' => 'pending',
            ]);
        }

        return $doc->fresh();
    }

    /**
     * Retrieve raw text content from the stored document.
     * For MVP: reads plain text files or returns placeholder for PDFs/images.
     * In production: this would call a real OCR API (Google Vision, Azure, Textract).
     */
    private function getRawTextFromDocument(Document $doc): string
    {
        try {
            $contents = \Illuminate\Support\Facades\Storage::disk($doc->storage_disk)
                ->get($doc->storage_path);

            // For text-based files, return raw content
            if (in_array($doc->mime_type, ['text/plain', 'text/csv'])) {
                return (string) $contents;
            }

            // For PDFs and images — in production this would call a real OCR API.
            // For MVP/testing: return the raw bytes as string (tests supply fake text files).
            return (string) $contents;
        } catch (\Exception $e) {
            return '';
        }
    }
}
