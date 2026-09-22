<?php

namespace App\Domain\Organization\Models;

use App\Domain\Organization\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Entity extends Model
{
    use HasFactory, HasUuids, BelongsToOrganization;

    protected $table = 'entities';

    protected $fillable = [
        'organization_id',
        'name',
        'code',
        'currency',
        'is_primary',
        'status',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
    ];

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class, 'entity_id');
    }
}
