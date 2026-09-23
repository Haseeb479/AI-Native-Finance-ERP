<?php

namespace App\Domain\Close\Models;

use App\Domain\Accounting\Period\Models\AccountingPeriod;
use App\Domain\Organization\Traits\BelongsToOrganization;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CloseCycle extends Model
{
    use HasUuids, BelongsToOrganization;

    protected $table = 'close_cycles';

    protected $fillable = [
        'organization_id',
        'accounting_period_id',
        'status',
        'total_tasks_count',
        'completed_tasks_count',
        'progress_percent',
        'notes',
        'closed_by',
        'closed_at',
    ];

    protected $casts = [
        'total_tasks_count' => 'integer',
        'completed_tasks_count' => 'integer',
        'progress_percent' => 'decimal:2',
        'closed_at' => 'datetime',
    ];

    public function period(): BelongsTo
    {
        return $this->belongsTo(AccountingPeriod::class, 'accounting_period_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(CloseTask::class, 'close_cycle_id')->orderBy('sort_order');
    }

    public function closedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function recalculateProgress(): void
    {
        $total = $this->tasks()->count();
        $completed = $this->tasks()->where('is_completed', true)->count();
        $percent = $total > 0 ? round(($completed / $total) * 100, 2) : 0.00;

        $this->update([
            'total_tasks_count' => $total,
            'completed_tasks_count' => $completed,
            'progress_percent' => $percent,
            'status' => ($total > 0 && $completed === $total) ? 'completed' : 'in_progress',
        ]);
    }
}
