<?php

namespace App\Domain\Shared\Outbox\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class OutboxEvent extends Model
{
    use HasUuids;

    protected $table = 'outbox_events';

    protected $fillable = [
        'id',
        'event_type',
        'aggregate_type',
        'aggregate_id',
        'organization_id',
        'correlation_id',
        'payload',
        'status',
        'retry_count',
        'error_message',
        'published_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'published_at' => 'datetime',
        'retry_count' => 'integer',
    ];

    public function markAsPublished(): void
    {
        $this->update([
            'status' => 'published',
            'published_at' => now(),
            'error_message' => null,
        ]);
    }

    public function markAsFailed(string $error): void
    {
        $this->update([
            'status' => 'failed',
            'retry_count' => $this->retry_count + 1,
            'error_message' => substr($error, 0, 1000),
        ]);
    }
}
