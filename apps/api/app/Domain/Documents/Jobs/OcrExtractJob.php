<?php

namespace App\Domain\Documents\Jobs;

use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Services\OcrService;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class OcrExtractJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Maximum number of attempts before marking as failed.
     */
    public int $tries = 3;

    /**
     * Delay between retries (seconds).
     */
    public int $backoff = 30;

    /**
     * Timeout in seconds for a single attempt.
     */
    public int $timeout = 60;

    public function __construct(
        public readonly string $documentId,
        public readonly ?int $userId = null,
    ) {}

    public function handle(OcrService $ocrService): void
    {
        $document = Document::withoutGlobalScopes()->find($this->documentId);

        if (!$document) {
            return; // Document was deleted; nothing to do
        }

        if (!in_array($document->ocr_status, ['pending', 'failed'])) {
            return; // Already processed
        }

        $user = $this->userId ? User::find($this->userId) : null;

        $ocrService->extractFromDocument($document, $user);
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        $document = Document::withoutGlobalScopes()->find($this->documentId);

        $document?->update([
            'ocr_status' => 'failed',
            'human_review_status' => 'pending',
        ]);
    }
}
