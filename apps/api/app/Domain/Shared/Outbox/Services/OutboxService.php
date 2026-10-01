<?php

namespace App\Domain\Shared\Outbox\Services;

use App\Domain\Shared\Events\DomainEvent;
use App\Domain\Shared\Outbox\Models\OutboxEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Throwable;

class OutboxService
{
    /**
     * Persist a domain event into the transactional outbox table within current DB transaction (P2-08).
     */
    public function recordEvent(DomainEvent $event): OutboxEvent
    {
        return OutboxEvent::create([
            'id' => $event->eventId,
            'event_type' => $event->eventType(),
            'aggregate_type' => $event->aggregateType(),
            'aggregate_id' => $event->aggregateId(),
            'organization_id' => $event->organizationId,
            'correlation_id' => $event->correlationId,
            'payload' => $event->toPayload(),
            'status' => 'pending',
            'retry_count' => 0,
        ]);
    }

    /**
     * Process and dispatch pending outbox events to registered listeners and integration workers.
     */
    public function publishPending(int $batchSize = 50): int
    {
        $publishedCount = 0;

        // Fetch pending events with row-level locks
        $pendingEvents = OutboxEvent::where('status', 'pending')
            ->where('retry_count', '<', 5)
            ->orderBy('created_at', 'asc')
            ->limit($batchSize)
            ->lockForUpdate()
            ->get();

        foreach ($pendingEvents as $outboxRecord) {
            try {
                // Dispatch domain event through Laravel Event system
                Event::dispatch($outboxRecord->event_type, [
                    'id' => $outboxRecord->id,
                    'aggregate_id' => $outboxRecord->aggregate_id,
                    'organization_id' => $outboxRecord->organization_id,
                    'payload' => $outboxRecord->payload,
                    'correlation_id' => $outboxRecord->correlation_id,
                ]);

                $outboxRecord->markAsPublished();
                $publishedCount++;

                Log::info("Outbox event published successfully", [
                    'event_id' => $outboxRecord->id,
                    'event_type' => $outboxRecord->event_type,
                    'aggregate_id' => $outboxRecord->aggregate_id,
                    'correlation_id' => $outboxRecord->correlation_id,
                ]);
            } catch (Throwable $e) {
                $outboxRecord->markAsFailed($e->getMessage());
                Log::error("Failed to publish outbox event", [
                    'event_id' => $outboxRecord->id,
                    'event_type' => $outboxRecord->event_type,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $publishedCount;
    }

    /**
     * Record failure on an outbox event.
     */
    public function recordFailure(OutboxEvent $event, string $error): void
    {
        $event->markAsFailed($error);
    }
}


