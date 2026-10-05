<?php

namespace App\Domain\ControlCenter\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class StaffAuditEvent extends Model
{
    public $timestamps = false;

    protected $table = 'staff_audit_events';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }

    protected function performUpdate(\Illuminate\Database\Eloquent\Builder $query): bool
    {
        throw new LogicException('Staff audit events are append-only.');
    }

    protected function performDeleteOnModel(): void
    {
        throw new LogicException('Staff audit events are append-only.');
    }
}
