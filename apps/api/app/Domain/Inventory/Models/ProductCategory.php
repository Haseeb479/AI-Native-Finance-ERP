<?php

namespace App\Domain\Inventory\Models;

use App\Domain\Organization\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductCategory extends Model
{
    use HasUuids, BelongsToOrganization;

    protected $table = 'product_categories';

    protected $fillable = [
        'organization_id',
        'name',
        'slug',
        'description',
    ];

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'category_id');
    }
}
