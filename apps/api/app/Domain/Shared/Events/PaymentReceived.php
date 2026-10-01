<?php

namespace App\Domain\Shared\Events;

class PaymentReceived extends DomainEvent
{
    public function __construct(
        string $organizationId,
        public readonly string $paymentId,
        public readonly string $invoiceId,
        public readonly string $amount,
        ?string $correlationId = null,
    ) {
        parent::__construct($organizationId, $correlationId);
    }

    public function eventType(): string { return 'sales.payment.received'; }
    public function aggregateType(): string { return 'payment'; }
    public function aggregateId(): string { return $this->paymentId; }
    public function toPayload(): array {
        return [
            'payment_id' => $this->paymentId,
            'invoice_id' => $this->invoiceId,
            'amount' => $this->amount,
        ];
    }
}
