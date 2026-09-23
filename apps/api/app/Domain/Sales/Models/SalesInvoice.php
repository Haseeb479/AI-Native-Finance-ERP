<?php

namespace App\Domain\Sales\Models;

use App\Domain\Accounting\Journal\Models\JournalEntry;
use App\Domain\Organization\Traits\BelongsToOrganization;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SalesInvoice extends Model
{
    use HasUuids, BelongsToOrganization, SoftDeletes;

    protected $table = 'sales_invoices';

    protected $fillable = [
        'organization_id',
        'customer_id',
        'journal_entry_id',
        'invoice_number',
        'issue_date',
        'due_date',
        'status',
        'currency',
        'exchange_rate',
        'subtotal',
        'tax_rate',
        'tax_amount',
        'total_amount',
        'amount_paid',
        'notes',
        'terms',
        'created_by',
        'posted_at',
        'approved_by',
        'approved_at',
        'rejected_by',
        'rejected_at',
        'rejection_reason',
    ];

    protected $casts = [
        'issue_date' => 'date',
        'due_date' => 'date',
        'exchange_rate' => 'decimal:6',
        'subtotal' => 'decimal:4',
        'tax_rate' => 'decimal:2',
        'tax_amount' => 'decimal:4',
        'total_amount' => 'decimal:4',
        'amount_paid' => 'decimal:4',
        'posted_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SalesInvoiceLine::class, 'sales_invoice_id')->orderBy('line_number');
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
        return max(0.00, (float) $this->total_amount - (float) $this->amount_paid);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejecter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function isPendingApproval(): bool
    {
        return $this->status === 'pending_approval';
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function isRejected(): bool
    {
        return $this->status === 'rejected';
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isPosted(): bool
    {
        return in_array($this->status, ['sent', 'paid', 'partial']);
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
        return $query->whereIn('status', ['sent', 'partial']);
    }
}
