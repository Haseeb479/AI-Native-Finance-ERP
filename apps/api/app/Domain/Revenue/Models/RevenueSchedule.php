<?php

namespace App\Domain\Revenue\Models;

use App\Domain\Accounting\Journal\Models\JournalEntry;
use App\Domain\Accounting\Period\Models\AccountingPeriod;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Traits\BelongsToOrganization;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RevenueSchedule extends Model
{
    use HasFactory, HasUuids, BelongsToOrganization;

    protected $table = 'revenue_schedules';

    protected $fillable = [
        'organization_id',
        'revenue_contract_id',
        'accounting_period_id',
        'schedule_date',
        'amount',
        'cumulative_recognized',
        'status',
        'journal_entry_id',
        'recognized_at',
        'recognized_by',
        'notes',
    ];

    protected $casts = [
        'schedule_date' => 'date',
        'amount' => 'decimal:4',
        'cumulative_recognized' => 'decimal:4',
        'recognized_at' => 'datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(RevenueContract::class, 'revenue_contract_id');
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(AccountingPeriod::class, 'accounting_period_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function recognizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recognized_by');
    }
}
