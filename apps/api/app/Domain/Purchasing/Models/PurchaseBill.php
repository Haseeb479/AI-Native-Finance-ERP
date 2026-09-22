<?php

namespace App\Domain\Purchasing\Models;

use App\Domain\Accounting\Journal\Models\JournalEntry;
use App\Domain\Organization\Traits\BelongsToOrganization;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PurchaseBill extends Model
{
    use HasUuids, BelongsToOrganization, SoftDeletes;

    protected $table = 'purchase_bills';

    protected $fillable = [
        'organization_id',
        'vendor_id',
        'journal_entry_id',
        'bill_number',
        'vendor_invoice_ref',
        'bill_date',
        'due_date',
        'status',
        'currency',
        'exchange_rate',
        'subtotal',
        'wht_rate',
        'wht_amount',
        'tax_amount',
        'total_amount',
        'net_payable',
        'amount_paid',
        'notes',
        'created_by',
        'posted_at',
    ];

    protected $casts = [
        'bill_date' => 'date',
        'due_date' => 'date',
        'exchange_rate' => 'decimal:6',
        'subtotal' => 'decimal:4',
        'wht_rate' => 'decimal:2',
        'wht_amount' => 'decimal:4',
        'tax_amount' => 'decimal:4',
        'total_amount' => 'decimal:4',
        'net_payable' => 'decimal:4',
        'amount_paid' => 'decimal:4',
        'posted_at' => 'datetime',
    ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseBillLine::class, 'purchase_bill_id')->orderBy('line_number');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function balanceDue(): float
    {
        return max(0.00, (float) $this->net_payable - (float) $this->amount_paid);
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isPosted(): bool
    {
        return in_array($this->status, ['received', 'paid', 'partial']);
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }

    public function isOverdue(): bool
    {
        return ! $this->isPaid() && $this->due_date->isPast();
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', ['received', 'partial']);
    }
}
