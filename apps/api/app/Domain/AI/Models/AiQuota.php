<?php

namespace App\Domain\AI\Models;

use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Traits\BelongsToOrganization;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiQuota extends Model
{
    use HasFactory, HasUuids, BelongsToOrganization;

    protected $table = 'ai_quotas';

    protected $fillable = [
        'organization_id',
        'user_id',
        'feature',
        'monthly_token_quota',
        'monthly_spend_quota',
        'tokens_used_this_month',
        'spend_used_this_month',
        'hard_limit_enabled',
        'soft_alert_threshold_percent',
        'last_reset_date',
    ];

    protected $casts = [
        'monthly_token_quota' => 'integer',
        'monthly_spend_quota' => 'decimal:4',
        'tokens_used_this_month' => 'integer',
        'spend_used_this_month' => 'decimal:4',
        'hard_limit_enabled' => 'boolean',
        'soft_alert_threshold_percent' => 'integer',
        'last_reset_date' => 'date',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Check if a reset is required due to entering a new billing month.
     */
    public function resetIfNewMonth(): void
    {
        $currentMonth = now()->format('Y-m');
        $lastMonth = $this->last_reset_date ? $this->last_reset_date->format('Y-m') : null;

        if ($currentMonth !== $lastMonth) {
            $this->tokens_used_this_month = 0;
            $this->spend_used_this_month = 0;
            $this->last_reset_date = now()->toDateString();
            $this->save();
        }
    }

    /**
     * Determine if quota has reached or exceeded hard limit.
     */
    public function isExceeded(): bool
    {
        $this->resetIfNewMonth();

        if (! $this->hard_limit_enabled) {
            return false;
        }

        return $this->tokens_used_this_month >= $this->monthly_token_quota
            || (float) $this->spend_used_this_month >= (float) $this->monthly_spend_quota;
    }

    /**
     * Check if soft warning threshold has been breached.
     */
    public function isSoftLimitReached(): bool
    {
        $this->resetIfNewMonth();

        $tokenPct = ($this->tokens_used_this_month / max(1, $this->monthly_token_quota)) * 100;
        $spendPct = ((float) $this->spend_used_this_month / max(0.0001, (float) $this->monthly_spend_quota)) * 100;

        return $tokenPct >= $this->soft_alert_threshold_percent || $spendPct >= $this->soft_alert_threshold_percent;
    }

    /**
     * Record consumption from real provider usage.
     */
    public function recordUsage(int $tokens, float $cost): void
    {
        $this->resetIfNewMonth();

        $this->increment('tokens_used_this_month', $tokens);
        $this->spend_used_this_month = (float) $this->spend_used_this_month + $cost;
        $this->save();
    }
}
