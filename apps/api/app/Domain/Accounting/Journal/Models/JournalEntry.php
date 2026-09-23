<?php

namespace App\Domain\Accounting\Journal\Models;

use App\Domain\Accounting\Period\Models\AccountingPeriod;
use App\Domain\Organization\Traits\BelongsToOrganization;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JournalEntry extends Model
{
    use HasUuids, BelongsToOrganization;

    protected $table = 'journal_entries';

    protected $fillable = [
        'organization_id',
        'entity_id',
        'accounting_period_id',
        'entry_number',
        'entry_date',
        'status',
        'source_type',
        'source_id',
        'description',
        'currency',
        'exchange_rate',
        'total_amount',
        'posted_at',
        'posted_by',
        'reversal_of_id',
        'created_by',
    ];

    protected $casts = [
        'entry_date' => 'date',
        'exchange_rate' => 'decimal:6',
        'total_amount' => 'decimal:4',
        'posted_at' => 'datetime',
    ];

    public function entity(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\Organization\Models\Entity::class, 'entity_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class, 'journal_entry_id')->orderBy('line_number');
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(AccountingPeriod::class, 'accounting_period_id');
    }

    public function postedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'reversal_of_id');
    }

    public function reversals(): HasMany
    {
        return $this->hasMany(JournalEntry::class, 'reversal_of_id');
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isPosted(): bool
    {
        return $this->status === 'posted';
    }

    public function isVoided(): bool
    {
        return $this->status === 'voided';
    }

    public function totalDebit(): float
    {
        return (float) $this->lines->sum(fn ($line) => (float) $line->debit);
    }

    public function totalCredit(): float
    {
        return (float) $this->lines->sum(fn ($line) => (float) $line->credit);
    }

    /**
     * Invariant: Total Debit == Total Credit
     */
    public function isBalanced(): bool
    {
        return abs($this->totalDebit() - $this->totalCredit()) < 0.0001 && $this->totalDebit() > 0;
    }

    public function scopePosted(Builder $query): Builder
    {
        return $query->where('status', 'posted');
    }

    public function scopeDraft(Builder $query): Builder
    {
        return $query->where('status', 'draft');
    }
}
