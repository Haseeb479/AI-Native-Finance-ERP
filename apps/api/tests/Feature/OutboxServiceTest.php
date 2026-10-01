<?php

namespace Tests\Feature;

use App\Domain\Shared\Events\JournalPosted;
use App\Domain\Shared\Outbox\Models\OutboxEvent;
use App\Domain\Shared\Outbox\Services\OutboxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class OutboxServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_record_and_publish_outbox_event(): void
    {
        $service = app(OutboxService::class);

        $event = new JournalPosted(
            organizationId: 'org-test-123',
            journalId: 'jr-999',
            entryNumber: 'JE-2026-0001',
            totalAmount: '50000.0000',
            correlationId: 'corr-test-abc'
        );

        // 1. Record event
        $outboxRecord = $service->recordEvent($event);

        $this->assertDatabaseHas('outbox_events', [
            'id' => $outboxRecord->id,
            'event_type' => 'accounting.journal.posted',
            'aggregate_id' => 'jr-999',
            'status' => 'pending',
            'correlation_id' => 'corr-test-abc',
        ]);

        // 2. Publish pending
        Event::fake(['accounting.journal.posted']);

        $publishedCount = $service->publishPending(10);
        $this->assertEquals(1, $publishedCount);

        $outboxRecord->refresh();
        $this->assertEquals('published', $outboxRecord->status);
        $this->assertNotNull($outboxRecord->published_at);

        Event::assertDispatched('accounting.journal.posted');
    }

    public function test_artisan_outbox_process_command(): void
    {
        $this->artisan('outbox:process --batch=10')
            ->assertExitCode(0);
    }
}
