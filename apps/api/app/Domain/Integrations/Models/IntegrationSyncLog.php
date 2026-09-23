<?php

namespace App\Domain\Integrations\Models;

use App\Domain\Organization\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntegrationSyncLog extends Model
{
    use HasUuids, BelongsToOrganization;

    protected $table = 'integration_sync_logs';

    protected $fillable = [
        'organization_id',
        'integration_id',
        'event',
        'status',
        'request_payload',
        'response_payload',
        'error_details',
        'retry_count',
        'max_retries',
    ];

    protected $casts = [
        'request_payload' => 'array',
        'response_payload' => 'array',
        'retry_count' => 'integer',
        'max_retries' => 'integer',
    ];

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class, 'integration_id');
    }

    public function canRetry(): bool
    {
        return $this->status === 'failed' && $this->retry_count < $this->max_retries;
    }
}
