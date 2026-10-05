<?php

namespace App\Domain\ControlCenter\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class StaffMember extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'control_center_staff';

    protected $guarded = [];

    protected static function booted(): void
    {
        static::creating(function (self $staffMember): void {
            $staffMember->id ??= (string) Str::uuid();
        });
    }
}
