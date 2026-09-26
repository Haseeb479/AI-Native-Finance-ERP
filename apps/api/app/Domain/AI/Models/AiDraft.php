<?php

namespace App\Domain\AI\Models;

use App\Domain\Organization\Models\Entity;
use App\Domain\Organization\Traits\BelongsToOrganization;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiDraft extends Model
{
    use HasUuids, BelongsToOrganization;

    protected $table = 'ai_drafts';

    protected $fillable = [
        'organization_id',
        'entity_id',
        'user_id',
        'draft_type',
        'title',
        'input_context',
        'proposed_payload',
        'validation_result',
        'evidence',
        'status',
        'rejection_reason',
        'approved_by',
        'approved_at',
        'resulting_record_type',
        'resulting_record_id',
    ];

    protected $casts = [
        'input_context' => 'array',
        'proposed_payload' => 'array',
        'validation_result' => 'array',
        'evidence' => 'array',
        'approved_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function approvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_id');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending_review';
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function isRejected(): bool
    {
        return $this->status === 'rejected';
    }

    public function isPosted(): bool
    {
        return $this->status === 'posted';
    }
}
