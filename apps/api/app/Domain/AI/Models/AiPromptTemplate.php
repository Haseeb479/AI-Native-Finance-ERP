<?php

namespace App\Domain\AI\Models;

use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiPromptTemplate extends Model
{
    use HasFactory, HasUuids, BelongsToOrganization;

    protected $table = 'ai_prompt_templates';

    protected $fillable = [
        'organization_id',
        'key',
        'version',
        'system_prompt',
        'user_prompt_template',
        'model_target',
        'is_active',
    ];

    protected $casts = [
        'version' => 'integer',
        'is_active' => 'boolean',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
