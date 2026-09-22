<?php

namespace App\Domain\Organization\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Organization extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'organizations';

    protected $fillable = [
        'name',
        'legal_name',
        'ntn',
        'strn',
        'country_code',
        'base_currency',
        'fiscal_year_start_month',
        'status',
        'settings',
    ];

    protected $casts = [
        'settings' => 'array',
        'fiscal_year_start_month' => 'integer',
    ];

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'organization_user')
            ->withPivot('role', 'is_default')
            ->withTimestamps();
    }

    public function entities(): HasMany
    {
        return $this->hasMany(Entity::class, 'organization_id');
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class, 'organization_id');
    }

    public function departments(): HasMany
    {
        return $this->hasMany(Department::class, 'organization_id');
    }
}
