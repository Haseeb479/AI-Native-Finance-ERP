<?php

namespace App\Domain\Procurement\Models;

use App\Domain\Organization\Traits\BelongsToOrganization;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PurchaseRequisition extends Model
{
    use HasUuids, BelongsToOrganization, SoftDeletes;

    protected $table = 'purchase_requisitions';

    protected $fillable = [
        'organization_id',
        'requisition_number',
        'status',
        'requested_date',
        'required_by_date',
        'purpose',
        'estimated_total',
        'requested_by',
        'approved_by',
        'approved_at',
        'rejected_by',
        'rejected_at',
        'rejection_reason',
    ];

    protected $casts = [
        'requested_date' => 'date',
        'required_by_date' => 'date',
        'estimated_total' => 'decimal:4',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseRequisitionLine::class, 'purchase_requisition_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isPendingApproval(): bool
    {
        return $this->status === 'pending_approval';
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }
}
