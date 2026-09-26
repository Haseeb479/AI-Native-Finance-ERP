<?php

namespace App\Domain\Accounting\Period\Models;

use App\Domain\Organization\Traits\BelongsToOrganization;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountingPeriod extends Model
{
    use HasUuids, BelongsToOrganization;

    protected $table = 'accounting_periods';

    protected $fillable = [
        'organization_id',
        'fiscal_year_id',
        'period_number',
        'name',
        'start_date',
        'end_date',
        'status',
        'closed_at',
        'closed_by',
        'reopened_at',
        'reopened_by',
        'reopen_reason',
    ];

    protected $casts = [
        'period_number' => 'integer',
        'start_date' => 'date',
        'end_date' => 'date',
        'closed_at' => 'datetime',
        'reopened_at' => 'datetime',
    ];

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class, 'fiscal_year_id');
    }

    public function closedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function reopenedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reopened_by');
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    public function isSoftClosed(): bool
    {
        return $this->status === 'soft_closed';
    }

    public function isHardClosed(): bool
    {
        return in_array($this->status, ['hard_closed', 'closed', 'locked'], true);
    }

    public function isClosed(): bool
    {
        return $this->isHardClosed();
    }

    public function isLocked(): bool
    {
        return $this->isSoftClosed() || $this->isHardClosed();
    }

    public function canPostOperational(): bool
    {
        return $this->isOpen();
    }

    public function canPostAdjustments(): bool
    {
        return $this->isOpen() || $this->isSoftClosed();
    }

    public function canPost(): bool
    {
        return $this->isOpen();
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', 'open');
    }

    public function scopeForDate(Builder $query, Carbon|string $date): Builder
    {
        $dateStr = $date instanceof Carbon ? $date->toDateString() : (Carbon::parse($date)->toDateString());

        return $query->whereDate('start_date', '<=', $dateStr)
                     ->whereDate('end_date', '>=', $dateStr);
    }

    public function containsDate(Carbon|string $date): bool
    {
        $dateStr = $date instanceof Carbon ? $date->toDateString() : Carbon::parse($date)->toDateString();
        $startStr = $this->start_date instanceof Carbon ? $this->start_date->toDateString() : (string) $this->start_date;
        $endStr = $this->end_date instanceof Carbon ? $this->end_date->toDateString() : (string) $this->end_date;

        return $startStr <= $dateStr && $endStr >= $dateStr;
    }
}
