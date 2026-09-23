<?php

namespace App\Domain\Security\Services;

use App\Domain\Organization\Models\Organization;
use App\Domain\Security\Models\SecurityApiKey;
use App\Domain\Security\Models\SecurityEvent;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use InvalidArgumentException;

class SecurityService
{
    /**
     * Create an API Key for automated systems and integrations.
     */
    public function createApiKey(
        Organization $organization,
        string $name,
        array $allowedIps = [],
        int $rateLimit = 60,
        ?User $user = null
    ): array {
        $rawSecret = Str::random(40);
        $plainKey = "erp_live_" . $rawSecret;
        $keyHash = hash('sha256', $plainKey);

        $apiKey = SecurityApiKey::withoutGlobalScopes()->create([
            'organization_id' => $organization->id,
            'name' => $name,
            'key_prefix' => 'erp_live_',
            'key_hash' => $keyHash,
            'rate_limit_per_minute' => $rateLimit,
            'allowed_ips' => $allowedIps,
            'is_active' => true,
            'created_by' => $user?->id,
        ]);

        $this->logSecurityEvent(
            $organization->id,
            'api_key_created',
            'info',
            ['key_name' => $name, 'key_id' => $apiKey->id],
            $user
        );

        return [
            'plain_key' => $plainKey,
            'key' => $apiKey,
        ];
    }

    /**
     * Rotate secret for an existing API Key without breaking key ID reference.
     */
    public function rotateApiKey(SecurityApiKey $apiKey, ?User $user = null): array
    {
        if (! $apiKey->is_active || $apiKey->revoked_at !== null) {
            throw new InvalidArgumentException("Cannot rotate a revoked or inactive API key.");
        }

        $rawSecret = Str::random(40);
        $newPlainKey = "erp_live_" . $rawSecret;
        $newHash = hash('sha256', $newPlainKey);

        $apiKey->update([
            'key_hash' => $newHash,
            'secret_last_rotated_at' => now(),
        ]);

        $this->logSecurityEvent(
            $apiKey->organization_id,
            'secret_rotated',
            'warning',
            ['key_name' => $apiKey->name, 'key_id' => $apiKey->id],
            $user
        );

        return [
            'plain_key' => $newPlainKey,
            'key' => $apiKey->fresh(),
        ];
    }

    /**
     * Verify plain API key and return model if valid.
     */
    public function verifyApiKey(string $plainKey, ?string $clientIp = null): ?SecurityApiKey
    {
        $hash = hash('sha256', $plainKey);

        $key = SecurityApiKey::withoutGlobalScopes()
            ->where('key_hash', $hash)
            ->first();

        if (! $key || ! $key->isValid()) {
            return null;
        }

        // Verify IP whitelist if configured
        if (! empty($key->allowed_ips) && $clientIp) {
            if (! in_array($clientIp, $key->allowed_ips)) {
                $this->logSecurityEvent(
                    $key->organization_id,
                    'ip_blocked',
                    'warning',
                    ['attempted_ip' => $clientIp, 'key_id' => $key->id]
                );
                return null;
            }
        }

        $key->update(['last_used_at' => now()]);

        return $key;
    }

    /**
     * Revoke an API Key immediately.
     */
    public function revokeApiKey(SecurityApiKey $apiKey, ?User $user = null): SecurityApiKey
    {
        $apiKey->update([
            'is_active' => false,
            'revoked_at' => now(),
        ]);

        $this->logSecurityEvent(
            $apiKey->organization_id,
            'api_key_revoked',
            'warning',
            ['key_name' => $apiKey->name, 'key_id' => $apiKey->id],
            $user
        );

        return $apiKey->fresh();
    }

    /**
     * Check rate limiting (fixed window algorithm).
     */
    public function checkRateLimit(string $identifier, int $maxAttempts = 60, int $decaySeconds = 60): array
    {
        $cacheKey = "rate_limit:" . md5($identifier);
        $attempts = (int) Cache::get($cacheKey, 0);

        if ($attempts >= $maxAttempts) {
            return [
                'allowed' => false,
                'limit' => $maxAttempts,
                'remaining' => 0,
                'retry_after' => $decaySeconds,
            ];
        }

        $newAttempts = $attempts + 1;
        if ($attempts === 0) {
            Cache::put($cacheKey, $newAttempts, $decaySeconds);
        } else {
            Cache::increment($cacheKey);
        }

        return [
            'allowed' => true,
            'limit' => $maxAttempts,
            'remaining' => max(0, $maxAttempts - $newAttempts),
            'retry_after' => 0,
        ];
    }

    /**
     * Enforce strict tenant isolation guard.
     */
    public function enforceTenantIsolation(
        User $user,
        string $targetOrgId,
        ?string $ip = null,
        ?string $userAgent = null
    ): bool {
        $belongsToOrg = $user->organizations()
            ->where('organizations.id', $targetOrgId)
            ->exists();

        if (! $belongsToOrg) {
            $this->logSecurityEvent(
                $targetOrgId,
                'unauthorized_tenant_access',
                'critical',
                [
                    'user_id' => $user->id,
                    'user_email' => $user->email,
                    'target_org_id' => $targetOrgId,
                ],
                $user,
                $ip,
                $userAgent
            );

            return false;
        }

        return true;
    }

    /**
     * Log security event.
     */
    public function logSecurityEvent(
        ?string $orgId,
        string $eventType,
        string $severity = 'info',
        array $details = [],
        ?User $user = null,
        ?string $ip = null,
        ?string $userAgent = null
    ): SecurityEvent {
        return SecurityEvent::withoutGlobalScopes()->create([
            'organization_id' => $orgId,
            'event_type' => $eventType,
            'severity' => $severity,
            'ip_address' => $ip ?? request()->ip(),
            'user_agent' => $userAgent ?? request()->userAgent(),
            'details' => $details,
            'user_id' => $user?->id,
        ]);
    }
}
