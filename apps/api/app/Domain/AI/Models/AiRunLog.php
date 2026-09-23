<?php

namespace App\Domain\AI\Models;

use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Traits\BelongsToOrganization;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiRunLog extends Model
{
    use HasFactory, HasUuids, BelongsToOrganization;

    protected $table = 'ai_run_logs';

    protected $fillable = [
        'organization_id',
        'user_id',
        'prompt_key',
        'prompt_version',
        'provider',
        'model',
        'input_tokens',
        'output_tokens',
        'total_cost',
        'status',
        'latency_ms',
        'error_message',
        'metadata',
    ];

    protected $casts = [
        'prompt_version' => 'integer',
        'input_tokens' => 'integer',
        'output_tokens' => 'integer',
        'total_cost' => 'decimal:6',
        'latency_ms' => 'integer',
        'metadata' => 'array',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
