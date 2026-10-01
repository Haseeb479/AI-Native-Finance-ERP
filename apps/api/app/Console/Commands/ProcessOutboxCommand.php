<?php

namespace App\Console\Commands;

use App\Domain\Shared\Outbox\Services\OutboxService;
use Illuminate\Console\Command;

class ProcessOutboxCommand extends Command
{
    protected $signature = 'outbox:process {--batch=50 : Maximum number of events to dispatch per run}';

    protected $description = 'Process and dispatch pending transactional outbox events (P2-08)';

    public function handle(OutboxService $service): int
    {
        $batch = (int) $this->option('batch');
        $this->info("Processing pending transactional outbox events (batch: {$batch})...");

        $count = $service->publishPending($batch);

        $this->info("Successfully published {$count} outbox event(s).");
        return 0;
    }
}
