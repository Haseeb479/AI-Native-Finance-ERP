<?php

namespace App\Domain\Shared\Events;

class DocumentApproved extends DomainEvent
{
    public function __construct(
        string $organizationId,
        public readonly string $documentId,
        public readonly string $approvedByUserId,
        ?string $correlationId = null,
    ) {
        parent::__construct($organizationId, $correlationId);
    }

    public function eventType(): string { return 'document.approved'; }
    public function aggregateType(): string { return 'document'; }
    public function aggregateId(): string { return $this->documentId; }
    public function toPayload(): array {
        return [
            'document_id' => $this->documentId,
            'approved_by_user_id' => $this->approvedByUserId,
        ];
    }
}
