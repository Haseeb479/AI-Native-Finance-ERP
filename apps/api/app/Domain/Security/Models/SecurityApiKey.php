<?php

namespace App\Domain\Security\Models;

use App\Domain\Organization\Traits\BelongsToOrganization;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class SecurityApiKey extends Model
{
    use HasUuids, BelongsToOrganization, SoftDeletes;

    protected $table = 'security_api_keys';

    protected $fillable = [
        'organization_id',
        'name',
        'key_prefix',
        'key_hash',
        'rate_limit_per_minute',
        'allowed_ips',
        'is_active',
        'secret_last_rotated_at',
        'expires_at',
        'last_used_at',
        'revoked_at',
        'created_by',
    ];

    protected $casts = [
        'rate_limit_per_minute' => 'integer',
        'allowed_ips' => 'array',
        'is_active' => 'boolean',
        'secret_last_rotated_at' => 'datetime',
        'expires_at' => 'datetime',
        'last_used_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    protected $hidden = [
        'key_hash',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isValid(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($this->revoked_at !== null) {
            return false;
        }

        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return false;
        }

        return true;
    }
}
