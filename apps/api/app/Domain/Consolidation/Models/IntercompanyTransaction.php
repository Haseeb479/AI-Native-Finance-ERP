<?php

namespace App\Domain\Consolidation\Models;

use App\Domain\Accounting\Journal\Models\JournalEntry;
use App\Domain\Organization\Models\Entity;
use App\Domain\Organization\Traits\BelongsToOrganization;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntercompanyTransaction extends Model
{
    use HasUuids, BelongsToOrganization;

    protected $table = 'intercompany_transactions';

    protected $fillable = [
        'organization_id',
        'from_entity_id',
        'to_entity_id',
        'transaction_number',
        'transaction_date',
        'currency',
        'amount',
        'exchange_rate',
        'base_amount',
        'description',
        'status',
        'from_journal_entry_id',
        'to_journal_entry_id',
        'elimination_journal_entry_id',
        'eliminated_at',
        'created_by',
    ];

    protected $casts = [
        'transaction_date' => 'date',
        'amount' => 'decimal:4',
        'exchange_rate' => 'decimal:6',
        'base_amount' => 'decimal:4',
        'eliminated_at' => 'datetime',
    ];

    public function fromEntity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'from_entity_id');
    }

    public function toEntity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'to_entity_id');
    }

    public function fromJournal(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'from_journal_entry_id');
    }

    public function toJournal(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'to_journal_entry_id');
    }

    public function eliminationJournal(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'elimination_journal_entry_id');
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
