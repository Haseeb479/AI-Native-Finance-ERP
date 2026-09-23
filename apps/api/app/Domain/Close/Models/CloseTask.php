<?php

namespace App\Domain\Close\Models;

use App\Domain\Organization\Traits\BelongsToOrganization;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CloseTask extends Model
{
    use HasUuids, BelongsToOrganization;

    protected $table = 'close_tasks';

    protected $fillable = [
        'organization_id',
        'close_cycle_id',
        'task_key',
        'title',
        'description',
        'category',
        'assigned_to',
        'is_completed',
        'completed_at',
        'completed_by',
        'sort_order',
        'metadata',
    ];

    protected $casts = [
        'is_completed' => 'boolean',
        'completed_at' => 'datetime',
        'sort_order' => 'integer',
        'metadata' => 'array',
    ];

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(CloseCycle::class, 'close_cycle_id');
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function completedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }
}
