<?php

namespace App\Domain\Integrations\Models;

use App\Domain\Organization\Traits\BelongsToOrganization;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Integration extends Model
{
    use HasUuids, BelongsToOrganization, SoftDeletes;

    protected $table = 'integrations';

    protected $fillable = [
        'organization_id',
        'provider',
        'name',
        'status',
        'credentials',
        'settings',
        'sync_status',
        'last_synced_at',
        'error_message',
        'created_by',
    ];

    protected $casts = [
        'credentials' => 'array',
        'settings' => 'array',
        'last_synced_at' => 'datetime',
    ];

    protected $hidden = [
        'credentials',
    ];

    public function syncLogs(): HasMany
    {
        return $this->hasMany(IntegrationSyncLog::class, 'integration_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isConnected(): bool
    {
        return $this->status === 'connected';
    }
}
