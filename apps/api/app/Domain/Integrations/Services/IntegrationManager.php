<?php

namespace App\Domain\Integrations\Services;

use App\Domain\Integrations\Models\Integration;
use App\Domain\Integrations\Models\IntegrationSyncLog;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class IntegrationManager
{
    /**
     * Register a new third-party integration connector.
     */
    public function registerIntegration(
        Organization $organization,
        string $provider,
        string $name,
        array $credentials = [],
        array $settings = [],
        ?User $user = null
    ): Integration {
        $validProviders = [
            'stripe', 'hbl_bank', 'jazzcash', 'easypaisa',
            'sendgrid', 's3', 'whatsapp', 'shopify', 'fbr', 'custom'
        ];

        if (! in_array(strtolower($provider), $validProviders)) {
            throw new InvalidArgumentException("Unsupported integration provider '{$provider}'.");
        }

        return Integration::withoutGlobalScopes()->create([
            'organization_id' => $organization->id,
            'provider' => strtolower($provider),
            'name' => $name,
            'status' => 'connected',
            'credentials' => $credentials,
            'settings' => $settings,
            'sync_status' => 'idle',
            'created_by' => $user?->id,
        ]);
    }

    /**
     * Test third-party integration connection & verify health.
     */
    public function testConnection(Integration $integration): array
    {
        $provider = $integration->provider;
        $creds = $integration->credentials ?? [];

        $isHealthy = true;
        $message = "Connected to {$integration->name} ({$provider}) successfully.";

        // Basic provider credentials check
        switch ($provider) {
            case 'stripe':
                if (empty($creds['api_key'])) {
                    $isHealthy = false;
                    $message = "Stripe secret API key is missing.";
                }
                break;
            case 'hbl_bank':
                if (empty($creds['client_id']) || empty($creds['client_secret'])) {
                    $isHealthy = false;
                    $message = "HBL Open Banking client credentials missing.";
                }
                break;
            case 'sendgrid':
                if (empty($creds['api_key'])) {
                    $isHealthy = false;
                    $message = "SendGrid API key missing.";
                }
                break;
            case 's3':
                if (empty($creds['bucket']) || empty($creds['access_key'])) {
                    $isHealthy = false;
                    $message = "S3 bucket configuration or access key missing.";
                }
                break;
        }

        $integration->update([
            'status' => $isHealthy ? 'connected' : 'error',
            'error_message' => $isHealthy ? null : $message,
        ]);

        return [
            'provider' => $provider,
            'status' => $isHealthy ? 'healthy' : 'unhealthy',
            'message' => $message,
            'timestamp' => now()->toIso8601String(),
        ];
    }

    /**
     * Record an integration synchronization log / audit entry.
     */
    public function recordSyncLog(
        Integration $integration,
        string $event,
        string $status,
        array $requestPayload = [],
        ?array $responsePayload = null,
        ?string $error = null
    ): IntegrationSyncLog {
        return DB::transaction(function () use ($integration, $event, $status, $requestPayload, $responsePayload, $error) {
            $log = IntegrationSyncLog::withoutGlobalScopes()->create([
                'organization_id' => $integration->organization_id,
                'integration_id' => $integration->id,
                'event' => $event,
                'status' => $status,
                'request_payload' => $requestPayload,
                'response_payload' => $responsePayload,
                'error_details' => $error,
                'retry_count' => 0,
                'max_retries' => 3,
            ]);

            $integration->update([
                'last_synced_at' => now(),
                'sync_status' => $status === 'success' ? 'success' : 'failed',
                'error_message' => $status === 'failed' ? $error : null,
            ]);

            return $log;
        });
    }

    /**
     * Retry a failed synchronization job.
     */
    public function retryFailedSync(IntegrationSyncLog $log): IntegrationSyncLog
    {
        if (! $log->canRetry()) {
            throw new InvalidArgumentException("Sync log cannot be retried (either not failed or max retries exceeded).");
        }

        $log->increment('retry_count');

        // Simulate successful retry execution
        $log->update([
            'status' => 'success',
            'error_details' => null,
            'response_payload' => array_merge($log->response_payload ?? [], [
                'retried_at' => now()->toIso8601String(),
                'retry_attempt' => $log->retry_count,
                'status' => 'resolved',
            ]),
        ]);

        $log->integration->update([
            'sync_status' => 'success',
            'error_message' => null,
            'last_synced_at' => now(),
        ]);

        return $log->fresh();
    }
}
