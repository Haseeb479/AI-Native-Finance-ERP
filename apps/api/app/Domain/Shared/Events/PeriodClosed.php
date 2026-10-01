<?php

namespace App\Domain\Shared\Events;

class PeriodClosed extends DomainEvent
{
    public function __construct(
        string $organizationId,
        public readonly string $periodId,
        public readonly string $periodName,
        public readonly string $closedByUserId,
        ?string $correlationId = null,
    ) {
        parent::__construct($organizationId, $correlationId);
    }

    public function eventType(): string { return 'accounting.period.closed'; }
    public function aggregateType(): string { return 'accounting_period'; }
    public function aggregateId(): string { return $this->periodId; }
    public function toPayload(): array {
        return [
            'period_id' => $this->periodId,
            'period_name' => $this->periodName,
            'closed_by_user_id' => $this->closedByUserId,
        ];
    }
}
