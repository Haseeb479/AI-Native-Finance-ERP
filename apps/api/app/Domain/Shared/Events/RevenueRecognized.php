<?php

namespace App\Domain\Shared\Events;

class RevenueRecognized extends DomainEvent
{
    public function __construct(
        string $organizationId,
        public readonly string $contractId,
        public readonly string $scheduleId,
        public readonly string $amount,
        ?string $correlationId = null,
    ) {
        parent::__construct($organizationId, $correlationId);
    }

    public function eventType(): string { return 'revenue.schedule.recognized'; }
    public function aggregateType(): string { return 'revenue_schedule'; }
    public function aggregateId(): string { return $this->scheduleId; }
    public function toPayload(): array {
        return [
            'contract_id' => $this->contractId,
            'schedule_id' => $this->scheduleId,
            'amount' => $this->amount,
        ];
    }
}
