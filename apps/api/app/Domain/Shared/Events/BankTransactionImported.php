<?php

namespace App\Domain\Shared\Events;

class BankTransactionImported extends DomainEvent
{
    public function __construct(
        string $organizationId,
        public readonly string $bankAccountId,
        public readonly string $transactionId,
        public readonly string $amount,
        ?string $correlationId = null,
    ) {
        parent::__construct($organizationId, $correlationId);
    }

    public function eventType(): string { return 'banking.transaction.imported'; }
    public function aggregateType(): string { return 'bank_transaction'; }
    public function aggregateId(): string { return $this->transactionId; }
    public function toPayload(): array {
        return [
            'bank_account_id' => $this->bankAccountId,
            'transaction_id' => $this->transactionId,
            'amount' => $this->amount,
        ];
    }
}
