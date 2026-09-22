<?php

namespace App\Domain\Accounting\Journal\Models;

use App\Domain\Accounting\ChartOfAccounts\Models\Account;
use App\Domain\Organization\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JournalLine extends Model
{
    use HasUuids, BelongsToOrganization;

    protected $table = 'journal_lines';

    protected $fillable = [
        'organization_id',
        'journal_entry_id',
        'account_id',
        'line_number',
        'description',
        'debit',
        'credit',
        'currency',
    ];

    protected $casts = [
        'line_number' => 'integer',
        'debit' => 'decimal:4',
        'credit' => 'decimal:4',
    ];

    public function entry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->entry();
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }
}
