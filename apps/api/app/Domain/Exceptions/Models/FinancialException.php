<?php

namespace App\Domain\Exceptions\Models;

use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class FinancialException extends Model
{
    use HasUuids;

    protected $table = 'financial_exceptions';

    protected $fillable = [
        'id',
        'organization_id',
        'entity_id',
        'exception_type',
        'severity',
        'aggregate_type',
        'aggregate_id',
        'title',
        'description',
        'payload',
        'status',
        'assigned_to_user_id',
        'resolved_by_user_id',
        'resolution_notes',
        'resolved_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'resolved_at' => 'datetime',
    ];

    public function organization(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    public function assignedTo(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function resolvedBy(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }

    public function resolve(User $user, string $notes): void
    {
        $this->update([
            'status' => 'resolved',
            'resolved_by_user_id' => $user->id,
            'resolution_notes' => $notes,
            'resolved_at' => now(),
        ]);
    }
}
