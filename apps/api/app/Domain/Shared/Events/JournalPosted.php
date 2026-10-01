<?php

namespace App\Domain\Shared\Events;

class JournalPosted extends DomainEvent
{
    public function __construct(
        string $organizationId,
        public readonly string $journalId,
        public readonly string $entryNumber,
        public readonly string $totalAmount,
        ?string $correlationId = null,
    ) {
        parent::__construct($organizationId, $correlationId);
    }

    public function eventType(): string { return 'accounting.journal.posted'; }
    public function aggregateType(): string { return 'journal_entry'; }
    public function aggregateId(): string { return $this->journalId; }
    public function toPayload(): array {
        return [
            'journal_id' => $this->journalId,
            'entry_number' => $this->entryNumber,
            'total_amount' => $this->totalAmount,
        ];
    }
}
