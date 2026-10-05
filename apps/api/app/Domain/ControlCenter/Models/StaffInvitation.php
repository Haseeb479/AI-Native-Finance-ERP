<?php

namespace App\Domain\ControlCenter\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class StaffInvitation extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'control_center_staff_invitations';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $invitation): void {
            $invitation->id ??= (string) Str::uuid();
        });
    }
}
