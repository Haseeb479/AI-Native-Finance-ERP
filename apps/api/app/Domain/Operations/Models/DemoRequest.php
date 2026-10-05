<?php

namespace App\Domain\Operations\Models;

use Illuminate\Database\Eloquent\Model;

class DemoRequest extends Model
{
    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'company_name',
        'company_size',
        'role',
        'referral_source',
        'message',
        'status',
    ];
}
