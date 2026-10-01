<?php

namespace App\Domain\Shared\Events;

class InvoicePosted extends DomainEvent
{
    public function __construct(
        string $organizationId,
        public readonly string $invoiceId,
        public readonly string $invoiceNumber,
        public readonly string $totalAmount,
        public readonly string $customerId,
        ?string $correlationId = null,
    ) {
        parent::__construct($organizationId, $correlationId);
    }

    public function eventType(): string { return 'sales.invoice.posted'; }
    public function aggregateType(): string { return 'invoice'; }
    public function aggregateId(): string { return $this->invoiceId; }
    public function toPayload(): array {
        return [
            'invoice_id' => $this->invoiceId,
            'invoice_number' => $this->invoiceNumber,
            'total_amount' => $this->totalAmount,
            'customer_id' => $this->customerId,
        ];
    }
}
