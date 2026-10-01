<?php

namespace App\Domain\Shared\Events;

abstract class DomainEvent
{
    public readonly string $eventId;
    public readonly string $occurredAt;
    public readonly ?string $correlationId;

    public function __construct(
        public readonly string $organizationId,
        ?string $correlationId = null,
    ) {
        $this->eventId = (string) \Illuminate\Support\Str::uuid();
        $this->occurredAt = now()->toIso8601String();
        $this->correlationId = $correlationId ?? request()?->header('X-Correlation-ID');
    }

    abstract public function eventType(): string;
    abstract public function aggregateType(): string;
    abstract public function aggregateId(): string;
    abstract public function toPayload(): array;
}
