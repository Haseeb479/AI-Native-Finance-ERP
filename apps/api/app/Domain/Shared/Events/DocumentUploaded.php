<?php

namespace App\Domain\Shared\Events;

class DocumentUploaded extends DomainEvent
{
    public function __construct(
        string $organizationId,
        public readonly string $documentId,
        public readonly string $filename,
        public readonly string $mimeType,
        ?string $correlationId = null,
    ) {
        parent::__construct($organizationId, $correlationId);
    }

    public function eventType(): string { return 'document.uploaded'; }
    public function aggregateType(): string { return 'document'; }
    public function aggregateId(): string { return $this->documentId; }
    public function toPayload(): array {
        return [
            'document_id' => $this->documentId,
            'filename' => $this->filename,
            'mime_type' => $this->mimeType,
        ];
    }
}
