<?php

namespace App\Domain\Security\Models;

use App\Domain\Organization\Traits\BelongsToOrganization;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SecurityEvent extends Model
{
    use HasUuids, BelongsToOrganization;

    protected $table = 'security_events';

    protected $fillable = [
        'organization_id',
        'event_type',
        'severity',
        'ip_address',
        'user_agent',
        'details',
        'user_id',
    ];

    protected $casts = [
        'details' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
