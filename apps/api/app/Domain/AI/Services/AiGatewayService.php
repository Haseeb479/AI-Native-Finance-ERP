<?php

namespace App\Domain\AI\Services;

use App\Domain\AI\Exceptions\AiQuotaExceededException;
use App\Domain\AI\Models\AiQuota;
use App\Domain\AI\Models\AiRunLog;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use App\Domain\Reporting\Services\ReportingService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AiGatewayService
{
    private string $aiServiceUrl;

    public function __construct(
        private readonly PromptRegistry $promptRegistry,
        private readonly ReportingService $reportingService
    ) {
        $this->aiServiceUrl = config('services.ai.url', 'http://localhost:8001');
    }

    /**
     * Check if organization has available token and budget quota (P1-17).
     */
    public function assertQuotaAvailable(Organization $organization, ?User $user = null, string $feature = 'all'): AiQuota
    {
        $quota = AiQuota::firstOrCreate(
            ['organization_id' => $organization->id, 'feature' => $feature, 'user_id' => null],
            [
                'monthly_token_quota' => 500000,
                'monthly_spend_quota' => 50.0000,
                'tokens_used_this_month' => 0,
                'spend_used_this_month' => 0.0000,
                'hard_limit_enabled' => true,
                'soft_alert_threshold_percent' => 80,
                'last_reset_date' => now()->toDateString(),
            ]
        );

        if ($quota->isExceeded()) {
            throw new AiQuotaExceededException(
                "Organization {$organization->id} has exceeded its monthly AI quota for feature '{$feature}'. Limit: {$quota->monthly_token_quota} tokens / \${$quota->monthly_spend_quota}."
            );
        }

        return $quota;
    }

    /**
     * Record consumption against quota (P1-17).
     */
    public function recordQuotaConsumption(Organization $organization, ?User $user, string $feature, int $tokens, float $cost): void
    {
        $quota = AiQuota::where('organization_id', $organization->id)
            ->where('feature', $feature)
            ->whereNull('user_id')
            ->first();

        if ($quota) {
            $quota->recordUsage($tokens, $cost);
        }
    }

    /**
     * Extract provider-reported usage or fall back to estimation (P1-16).
     */
    private function resolveUsageMetrics(array $responseBody, int $estimatedInputTokens, int $estimatedOutputTokens): array
    {
        $usage = $responseBody['usage_metadata'] ?? [];
        $inputTokens = isset($usage['prompt_tokens']) ? (int) $usage['prompt_tokens'] : $estimatedInputTokens;
        $outputTokens = isset($usage['completion_tokens']) ? (int) $usage['completion_tokens'] : $estimatedOutputTokens;
        $cachedTokens = (int) ($usage['cached_tokens'] ?? 0);
        $requestId = $usage['request_id'] ?? null;
        $cost = isset($usage['actual_cost']) ? (float) $usage['actual_cost'] : ((($inputTokens + $outputTokens) / 1000) * 0.000075);

        return [
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'cached_tokens' => $cachedTokens,
            'provider_request_id' => $requestId,
            'total_cost' => round($cost, 6),
        ];
    }

    /**
     * Execute an AI classification task through the gateway with cost and token audit logging.
     */
    public function classifyTransaction(
        Organization $organization,
        User $user,
        string $description,
        float $amount,
        string $currency = 'PKR'
    ): array {
        $this->assertQuotaAvailable($organization, $user, 'classify_transaction');

        $startTime = microtime(true);
        $promptInfo = $this->promptRegistry->getTemplate('classify_transaction', $organization);

        $endpoint = "{$this->aiServiceUrl}/api/v1/classify/transaction";
        $payload = [
            'description' => $description,
            'amount' => $amount,
            'currency' => $currency,
            'organization_id' => $organization->id,
        ];

        $status = 'success';
        $errorMessage = null;
        $responseBody = [];
        $estInputTokens = (int) (strlen($description) / 4) + 60; // Approximate token estimation
        $estOutputTokens = 40;

        try {
            $response = Http::timeout(15)->post($endpoint, $payload);

            if (! $response->successful()) {
                $status = 'failed';
                $errorMessage = "AI microservice returned HTTP status {$response->status()}";
                throw new \RuntimeException($errorMessage);
            }

            $responseBody = $response->json();
        } catch (\Throwable $e) {
            $status = 'failed';
            $errorMessage = $e->getMessage();
            Log::warning('AI Gateway execution failed: ' . $errorMessage);
            throw $e;
        } finally {
            $latencyMs = (int) ((microtime(true) - $startTime) * 1000);
            $metrics = $this->resolveUsageMetrics($responseBody, $estInputTokens, $estOutputTokens);

            AiRunLog::create([
                'organization_id' => $organization->id,
                'user_id' => $user->id,
                'prompt_key' => 'classify_transaction',
                'prompt_version' => $promptInfo['version'],
                'provider' => 'gemini',
                'model' => $promptInfo['model'],
                'input_tokens' => $metrics['input_tokens'],
                'output_tokens' => $metrics['output_tokens'],
                'cached_tokens' => $metrics['cached_tokens'],
                'provider_request_id' => $metrics['provider_request_id'],
                'total_cost' => $metrics['total_cost'],
                'status' => $status,
                'latency_ms' => $latencyMs,
                'retry_count' => 0,
                'error_message' => $errorMessage,
                'metadata' => [
                    'amount' => $amount,
                    'currency' => $currency,
                ],
            ]);

            if ($status === 'success') {
                $this->recordQuotaConsumption(
                    $organization,
                    $user,
                    'classify_transaction',
                    $metrics['input_tokens'] + $metrics['output_tokens'],
                    $metrics['total_cost']
                );
            }
        }

        return $responseBody;
    }

    /**
     * Financial Q&A through Copilot with audit logging.
     */
    public function askCopilot(Organization $organization, User $user, string $query, array $context = []): array
    {
        $this->assertQuotaAvailable($organization, $user, 'financial_qa');

        $startTime = microtime(true);
        $promptInfo = $this->promptRegistry->getTemplate('financial_qa', $organization);

        // Server-authoritative financial facts (P0-04)
        $tb = $this->reportingService->trialBalance($organization, now()->toDateString());
        $authoritativeContext = [
            'as_of_date' => now()->toDateString(),
            'base_currency' => $organization->base_currency ?? 'PKR',
            'is_trial_balance_balanced' => $tb['is_balanced'],
            'total_debit' => $tb['totals']['total_debit'],
            'total_credit' => $tb['totals']['total_credit'],
            'top_accounts' => array_slice(array_map(fn ($a) => [
                'code' => $a['code'],
                'name' => $a['name'],
                'balance' => $a['net_balance'],
            ], $tb['accounts']), 0, 25),
        ];

        if (! empty($context)) {
            $authoritativeContext['user_query_metadata'] = $context;
        }

        $internalToken = $this->generateInternalServiceToken($organization, $user);
        $endpoint = "{$this->aiServiceUrl}/v1/copilot/qa";
        $payload = [
            'query' => $query,
            'organization_id' => $organization->id,
            'currency' => $organization->base_currency ?? 'PKR',
            'financial_context' => $authoritativeContext,
        ];

        $status = 'success';
        $errorMessage = null;
        $responseBody = [];
        $estInputTokens = (int) (strlen($query) / 4) + 120;
        $estOutputTokens = 90;

        try {
            $response = Http::withToken($internalToken)->timeout(20)->post($endpoint, $payload);
            if (! $response->successful()) {
                throw new \RuntimeException("Copilot service returned HTTP {$response->status()}");
            }
            $responseBody = $response->json();
        } catch (\Throwable $e) {
            $status = 'failed';
            $errorMessage = $e->getMessage();
            throw $e;
        } finally {
            $latencyMs = (int) ((microtime(true) - $startTime) * 1000);
            $metrics = $this->resolveUsageMetrics($responseBody, $estInputTokens, $estOutputTokens);

            AiRunLog::create([
                'organization_id' => $organization->id,
                'user_id' => $user->id,
                'prompt_key' => 'financial_qa',
                'prompt_version' => $promptInfo['version'],
                'provider' => 'gemini',
                'model' => $promptInfo['model'],
                'input_tokens' => $metrics['input_tokens'],
                'output_tokens' => $metrics['output_tokens'],
                'cached_tokens' => $metrics['cached_tokens'],
                'provider_request_id' => $metrics['provider_request_id'],
                'total_cost' => $metrics['total_cost'],
                'status' => $status,
                'latency_ms' => $latencyMs,
                'retry_count' => 0,
                'error_message' => $errorMessage,
                'metadata' => ['query' => substr($query, 0, 100)],
            ]);

            if ($status === 'success') {
                $this->recordQuotaConsumption(
                    $organization,
                    $user,
                    'financial_qa',
                    $metrics['input_tokens'] + $metrics['output_tokens'],
                    $metrics['total_cost']
                );
            }
        }

        return $responseBody;
    }

    /**
     * AI balanced double-entry journal draft proposition with audit logging.
     */
    public function draftJournal(Organization $organization, User $user, string $instruction, ?float $amount = null): array
    {
        $this->assertQuotaAvailable($organization, $user, 'journal_draft');

        $startTime = microtime(true);
        $promptInfo = $this->promptRegistry->getTemplate('journal_draft', $organization);

        $endpoint = "{$this->aiServiceUrl}/v1/copilot/draft-journal";
        $payload = [
            'instruction' => $instruction,
            'amount' => $amount,
            'currency' => $organization->base_currency ?? 'PKR',
            'organization_id' => $organization->id,
        ];

        $status = 'success';
        $errorMessage = null;
        $responseBody = [];
        $estInputTokens = (int) (strlen($instruction) / 4) + 100;
        $estOutputTokens = 120;

        try {
            $response = Http::timeout(20)->post($endpoint, $payload);
            if (! $response->successful()) {
                throw new \RuntimeException("Journal draft service returned HTTP {$response->status()}");
            }
            $responseBody = $response->json();
        } catch (\Throwable $e) {
            $status = 'failed';
            $errorMessage = $e->getMessage();
            throw $e;
        } finally {
            $latencyMs = (int) ((microtime(true) - $startTime) * 1000);
            $metrics = $this->resolveUsageMetrics($responseBody, $estInputTokens, $estOutputTokens);

            AiRunLog::create([
                'organization_id' => $organization->id,
                'user_id' => $user->id,
                'prompt_key' => 'journal_draft',
                'prompt_version' => $promptInfo['version'],
                'provider' => 'gemini',
                'model' => $promptInfo['model'],
                'input_tokens' => $metrics['input_tokens'],
                'output_tokens' => $metrics['output_tokens'],
                'cached_tokens' => $metrics['cached_tokens'],
                'provider_request_id' => $metrics['provider_request_id'],
                'total_cost' => $metrics['total_cost'],
                'status' => $status,
                'latency_ms' => $latencyMs,
                'retry_count' => 0,
                'error_message' => $errorMessage,
                'metadata' => ['amount' => $amount],
            ]);

            if ($status === 'success') {
                $this->recordQuotaConsumption(
                    $organization,
                    $user,
                    'journal_draft',
                    $metrics['input_tokens'] + $metrics['output_tokens'],
                    $metrics['total_cost']
                );
            }
        }

        return $responseBody;
    }

    /**
     * AI Report explanation & variance analysis with audit logging.
     */
    public function explainReport(Organization $organization, User $user, string $reportType, array $reportData, string $periodLabel = 'Current Period'): array
    {
        $this->assertQuotaAvailable($organization, $user, 'explain_report');

        $startTime = microtime(true);
        $promptInfo = $this->promptRegistry->getTemplate('explain_report', $organization);

        $endpoint = "{$this->aiServiceUrl}/v1/copilot/explain-report";
        $payload = [
            'report_type' => $reportType,
            'period_label' => $periodLabel,
            'report_data' => $reportData,
            'organization_id' => $organization->id,
        ];

        $status = 'success';
        $errorMessage = null;
        $responseBody = [];
        $estInputTokens = 250;
        $estOutputTokens = 180;

        try {
            $response = Http::timeout(20)->post($endpoint, $payload);
            if (! $response->successful()) {
                throw new \RuntimeException("Report explanation service returned HTTP {$response->status()}");
            }
            $responseBody = $response->json();
        } catch (\Throwable $e) {
            $status = 'failed';
            $errorMessage = $e->getMessage();
            throw $e;
        } finally {
            $latencyMs = (int) ((microtime(true) - $startTime) * 1000);
            $metrics = $this->resolveUsageMetrics($responseBody, $estInputTokens, $estOutputTokens);

            AiRunLog::create([
                'organization_id' => $organization->id,
                'user_id' => $user->id,
                'prompt_key' => 'explain_report',
                'prompt_version' => $promptInfo['version'],
                'provider' => 'gemini',
                'model' => $promptInfo['model'],
                'input_tokens' => $metrics['input_tokens'],
                'output_tokens' => $metrics['output_tokens'],
                'cached_tokens' => $metrics['cached_tokens'],
                'provider_request_id' => $metrics['provider_request_id'],
                'total_cost' => $metrics['total_cost'],
                'status' => $status,
                'latency_ms' => $latencyMs,
                'retry_count' => 0,
                'error_message' => $errorMessage,
                'metadata' => ['report_type' => $reportType, 'period' => $periodLabel],
            ]);

            if ($status === 'success') {
                $this->recordQuotaConsumption(
                    $organization,
                    $user,
                    'explain_report',
                    $metrics['input_tokens'] + $metrics['output_tokens'],
                    $metrics['total_cost']
                );
            }
        }

        return $responseBody;
    }

    /**
     * Retrieve aggregated AI usage metrics for an organization.
     */
    public function getUsageMetrics(Organization $organization): array
    {
        $logs = AiRunLog::where('organization_id', $organization->id);

        $totalRuns = (clone $logs)->count();
        $successfulRuns = (clone $logs)->where('status', 'success')->count();
        $totalInputTokens = (clone $logs)->sum('input_tokens');
        $totalOutputTokens = (clone $logs)->sum('output_tokens');
        $totalCost = (clone $logs)->sum('total_cost');

        return [
            'organization_id' => $organization->id,
            'total_runs' => $totalRuns,
            'successful_runs' => $successfulRuns,
            'failed_runs' => $totalRuns - $successfulRuns,
            'total_input_tokens' => (int) $totalInputTokens,
            'total_output_tokens' => (int) $totalOutputTokens,
            'total_tokens' => (int) ($totalInputTokens + $totalOutputTokens),
            'total_cost_usd' => round((float) $totalCost, 6),
        ];
    }

    /**
     * Execute a registered AI tool with authenticated internal service claims (P0-01).
     */
    public function executeAiTool(Organization $organization, User $user, string $toolName, array $arguments, ?string $entityId = null): array
    {
        $token = $this->generateInternalServiceToken($organization, $user, $entityId);
        $endpoint = "{$this->aiServiceUrl}/v1/tools/execute";
        $payload = [
            'tool_name' => $toolName,
            'arguments' => $arguments,
            'organization_id' => $organization->id,
            'entity_id' => $entityId,
            'user_id' => $user->id,
        ];

        $response = Http::withToken($token)->timeout(20)->post($endpoint, $payload);
        if (! $response->successful()) {
            throw new \RuntimeException("Tool microservice returned HTTP {$response->status()}: " . $response->body());
        }

        return $response->json();
    }

    /**
     * Generate short-lived signed JWT for Laravel -> FastAPI service-to-service authentication (P0-01).
     */
    public function generateInternalServiceToken(Organization $organization, User $user, ?string $entityId = null): string
    {
        $secret = config('services.ai.internal_secret', 'ai-native-finance-erp-internal-service-secret-key');
        $now = time();

        $header = ['typ' => 'JWT', 'alg' => 'HS256'];
        $claims = [
            'iss' => 'laravel-finance-erp',
            'aud' => 'ai-tool-gateway',
            'organization_id' => $organization->id,
            'entity_id' => $entityId,
            'user_id' => $user->id,
            'user_permissions' => $user->getPermissionsForOrganization($organization),
            'jti' => (string) \Illuminate\Support\Str::uuid(),
            'iat' => $now,
            'exp' => $now + 60, // 60 seconds TTL
        ];

        $encodedHeader = $this->base64UrlEncode(json_encode($header));
        $encodedPayload = $this->base64UrlEncode(json_encode($claims));
        $signature = hash_hmac('sha256', "{$encodedHeader}.{$encodedPayload}", $secret, true);
        $encodedSignature = $this->base64UrlEncode($signature);

        return "{$encodedHeader}.{$encodedPayload}.{$encodedSignature}";
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
