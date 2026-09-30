<?php

namespace App\Domain\Shared\Models;

use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Traits\BelongsToOrganization;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IdempotencyKey extends Model
{
    use HasUuids, BelongsToOrganization;

    protected $table = 'idempotency_keys';

    protected $fillable = [
        'organization_id',
        'user_id',
        'idempotency_key',
        'request_method',
        'request_path',
        'request_hash',
        'status',
        'response_code',
        'response_headers',
        'response_body',
        'locked_at',
    ];

    protected $casts = [
        'response_code' => 'integer',
        'response_headers' => 'array',
        'response_body' => 'array',
        'locked_at' => 'datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isInProgress(): bool
    {
        return $this->status === 'in_progress';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }
}
