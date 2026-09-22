<?php

namespace App\Domain\Banking\Models;

use App\Domain\Accounting\Journal\Models\JournalEntry;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Traits\BelongsToOrganization;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankTransaction extends Model
{
    use HasFactory, HasUuids, BelongsToOrganization;

    protected $table = 'bank_transactions';

    protected $fillable = [
        'organization_id',
        'bank_account_id',
        'bank_statement_id',
        'transaction_date',
        'description',
        'reference',
        'type',
        'amount',
        'balance_after',
        'reconciliation_status',
        'matched_journal_entry_id',
        'matched_at',
        'matched_by',
        'fingerprint',
    ];

    protected $casts = [
        'transaction_date' => 'date',
        'amount' => 'decimal:4',
        'balance_after' => 'decimal:4',
        'matched_at' => 'datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function bankStatement(): BelongsTo
    {
        return $this->belongsTo(BankStatement::class);
    }

    public function matchedJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'matched_journal_entry_id');
    }

    public function matcher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'matched_by');
    }

    public function isReconciled(): bool
    {
        return $this->reconciliation_status === 'reconciled';
    }

    public function isDeposit(): bool
    {
        return $this->type === 'credit';
    }

    public function isWithdrawal(): bool
    {
        return $this->type === 'debit';
    }

    /**
     * Compute a SHA256 deterministic fingerprint to prevent duplicate transactions.
     */
    public static function generateFingerprint(string $date, string $amount, ?string $reference, string $type): string
    {
        $normalizedDate = trim($date);
        $normalizedAmount = number_format((float) $amount, 4, '.', '');
        $normalizedRef = strtoupper(trim($reference ?? ''));
        $normalizedType = strtolower(trim($type));

        return hash('sha256', "{$normalizedDate}|{$normalizedAmount}|{$normalizedRef}|{$normalizedType}");
    }
}
