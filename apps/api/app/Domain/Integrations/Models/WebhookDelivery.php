<?php

namespace App\Domain\Integrations\Models;

use App\Domain\Organization\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebhookDelivery extends Model
{
    use HasUuids, BelongsToOrganization;

    protected $table = 'webhook_deliveries';

    protected $fillable = [
        'organization_id',
        'webhook_id',
        'event',
        'payload',
        'status',
        'response_status_code',
        'response_body',
        'attempts',
        'delivered_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'response_status_code' => 'integer',
        'attempts' => 'integer',
        'delivered_at' => 'datetime',
    ];

    public function webhook(): BelongsTo
    {
        return $this->belongsTo(Webhook::class, 'webhook_id');
    }
}
