<?php

namespace App\Domain\ControlCenter\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class SupportCase extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'control_center_support_cases';

    protected $fillable = [
        'organization_id',
        'customer_email',
        'category',
        'subject',
        'description',
        'priority',
        'status',
        'created_by_user_id',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $supportCase): void {
            $supportCase->id ??= (string) Str::uuid();
        });
    }
}
