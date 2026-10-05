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
     * Build distributed tracing and correlation headers for AI microservice calls (P1-35).
     */
    private function getCorrelationHeaders(?string $token = null): array
    {
        $correlationId = request()?->header('X-Correlation-ID')
            ?: \Illuminate\Support\Facades\Context::get('correlation_id')
            ?: (string) \Illuminate\Support\Str::uuid();

        $headers = [
            'X-Correlation-ID' => $correlationId,
        ];

        if ($token) {
            $headers['Authorization'] = "Bearer {$token}";
        }

        return $headers;
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

        $internalToken = $this->generateInternalServiceToken($organization, $user);
        $endpoint = "{$this->aiServiceUrl}/v1/classify/transaction";
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
            $response = Http::withHeaders($this->getCorrelationHeaders($internalToken))->timeout(15)->post($endpoint, $payload);

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
            $response = Http::withHeaders($this->getCorrelationHeaders($internalToken))->timeout(20)->post($endpoint, $payload);
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

        $internalToken = $this->generateInternalServiceToken($organization, $user);
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
            $response = Http::withHeaders($this->getCorrelationHeaders($internalToken))->timeout(20)->post($endpoint, $payload);
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

        $internalToken = $this->generateInternalServiceToken($organization, $user);
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
            $response = Http::withHeaders($this->getCorrelationHeaders($internalToken))->timeout(20)->post($endpoint, $payload);
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

        $response = Http::withHeaders($this->getCorrelationHeaders($token))->timeout(20)->post($endpoint, $payload);
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
        $secret = config('services.ai.internal_secret');
        if (app()->isProduction()) {
            if (empty($secret) || $secret === 'ai-native-finance-erp-internal-service-secret-key') {
                throw new \RuntimeException("Security Violation: Production environment cannot use default or empty services.ai.internal_secret.");
            }
        }
        $secret = $secret ?? 'ai-native-finance-erp-internal-service-secret-key';
        $now = time();

        $header = ['typ' => 'JWT', 'alg' => 'HS256'];
        $claims = [
            'iss' => 'laravel-finance-erp',
            'aud' => 'ai-tool-gateway',
            'organization_id' => $organization->id,
            'entity_id' => $entityId,
            'user_id' => (string) $user->id,
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

    /**
     * Generic invoker for AI Workflows (P3-05).
     */
    protected function callAiWorkflow(Organization $organization, User $user, string $action, array $payload, string $promptKey): array
    {
        $this->assertQuotaAvailable($organization, $user, $promptKey);

        $startTime = microtime(true);
        $internalToken = $this->generateInternalServiceToken($organization, $user);
        $endpoint = "{$this->aiServiceUrl}/v1/copilot/workflows/{$action}";

        $status = 'success';
        $errorMessage = null;
        $responseBody = [];
        $estInputTokens = 300;
        $estOutputTokens = 200;

        try {
            $response = Http::withHeaders($this->getCorrelationHeaders($internalToken))->timeout(25)->post($endpoint, $payload);
            if (! $response->successful()) {
                throw new \RuntimeException("AI workflow {$action} returned HTTP {$response->status()}");
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
                'prompt_key' => $promptKey,
                'prompt_version' => 1,
                'provider' => 'gemini',
                'model' => 'gemini-1.5-pro',
                'input_tokens' => $metrics['input_tokens'],
                'output_tokens' => $metrics['output_tokens'],
                'cached_tokens' => $metrics['cached_tokens'],
                'provider_request_id' => $metrics['provider_request_id'],
                'total_cost' => $metrics['total_cost'],
                'status' => $status,
                'latency_ms' => $latencyMs,
                'retry_count' => 0,
                'error_message' => $errorMessage,
                'metadata' => ['workflow' => $action],
            ]);

            if ($status === 'success') {
                $this->recordQuotaConsumption(
                    $organization,
                    $user,
                    $promptKey,
                    $metrics['input_tokens'] + $metrics['output_tokens'],
                    $metrics['total_cost']
                );
            }
        }

        return $responseBody;
    }

    /**
     * 1. AI Workflow: Prepare Month-End Close
     */
    public function workflowPrepareClose(Organization $organization, User $user, string $periodId): array
    {
        $period = \App\Domain\Accounting\Period\Models\AccountingPeriod::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->findOrFail($periodId);

        $tb = $this->reportingService->trialBalance($organization, $period->end_date->toDateString());
        $draftsCount = \App\Domain\Accounting\Journal\Models\JournalEntry::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->where('accounting_period_id', $period->id)
            ->where('status', 'draft')
            ->count();

        $unreconciledBankCount = \App\Domain\Banking\Models\BankTransaction::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->where('reconciliation_status', 'unreconciled')
            ->count();

        $openExceptionsCount = \App\Domain\Exceptions\Models\FinancialException::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->where('status', 'open')
            ->count();

        $cycle = \App\Domain\Close\Models\CloseCycle::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->where('accounting_period_id', $period->id)
            ->first();

        $deprRun = $cycle ? (bool) $cycle->tasks()->where('task_key', 'depreciation_entries')->where('is_completed', true)->exists() : false;
        $accrualsPosted = $cycle ? (bool) $cycle->tasks()->where('task_key', 'post_accruals')->where('is_completed', true)->exists() : false;

        $payload = [
            'organization_id' => $organization->id,
            'period_id' => $period->id,
            'period_name' => $period->name,
            'trial_balance_balanced' => (bool) $tb['is_balanced'],
            'draft_journals_count' => $draftsCount,
            'unreconciled_bank_count' => $unreconciledBankCount,
            'depreciation_run' => $deprRun,
            'accruals_posted' => $accrualsPosted,
            'open_exceptions_count' => $openExceptionsCount,
        ];

        return $this->callAiWorkflow($organization, $user, 'prepare-close', $payload, 'workflow_close');
    }

    /**
     * 2. AI Workflow: Find Unreconciled Transactions
     */
    public function workflowUnreconciled(Organization $organization, User $user, ?string $bankAccountId = null): array
    {
        $query = \App\Domain\Banking\Models\BankTransaction::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->where('reconciliation_status', 'unreconciled')
            ->orderBy('transaction_date', 'desc')
            ->limit(50);

        if ($bankAccountId) {
            $query->where('bank_account_id', $bankAccountId);
        }

        $records = $query->get()->map(fn ($t) => [
            'id' => $t->id,
            'date' => $t->transaction_date->toDateString(),
            'amount' => (string) $t->amount,
            'description' => $t->description,
            'reference' => $t->reference,
        ])->toArray();

        $payload = [
            'organization_id' => $organization->id,
            'bank_account_id' => $bankAccountId,
            'unreconciled_items' => $records,
        ];

        return $this->callAiWorkflow($organization, $user, 'unreconciled-transactions', $payload, 'workflow_unreconciled');
    }

    /**
     * 3. AI Workflow: Explain Margin Changes
     */
    public function workflowMarginAnalysis(Organization $organization, User $user, string $currentFrom, string $currentTo, string $priorFrom, string $priorTo): array
    {
        $currentPnl = $this->reportingService->profitAndLoss($organization, $currentFrom, $currentTo);
        $priorPnl = $this->reportingService->profitAndLoss($organization, $priorFrom, $priorTo);

        $curRev = (float) ($currentPnl['revenue']['total'] ?? 0);
        $curCogs = (float) ($currentPnl['cost_of_goods_sold']['total'] ?? 0);
        $curMarginPct = $curRev > 0 ? round((($curRev - $curCogs) / $curRev) * 100, 2) : 0;

        $priorRev = (float) ($priorPnl['revenue']['total'] ?? 0);
        $priorCogs = (float) ($priorPnl['cost_of_goods_sold']['total'] ?? 0);
        $priorMarginPct = $priorRev > 0 ? round((($priorRev - $priorCogs) / $priorRev) * 100, 2) : 0;

        $payload = [
            'organization_id' => $organization->id,
            'current_period' => "{$currentFrom} to {$currentTo}",
            'prior_period' => "{$priorFrom} to {$priorTo}",
            'current_revenue' => (string) $curRev,
            'current_cogs' => (string) $curCogs,
            'current_gross_margin_pct' => (string) $curMarginPct,
            'prior_revenue' => (string) $priorRev,
            'prior_cogs' => (string) $priorCogs,
            'prior_gross_margin_pct' => (string) $priorMarginPct,
            'operating_expenses_change_pct' => '0.0',
        ];

        return $this->callAiWorkflow($organization, $user, 'margin-analysis', $payload, 'workflow_margin');
    }

    /**
     * 4. AI Workflow: Prepare Invoice Approval Queue
     */
    public function workflowInvoiceApprovalQueue(Organization $organization, User $user): array
    {
        $bills = \App\Domain\Purchasing\Models\PurchaseBill::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->where('status', 'draft')
            ->with('vendor')
            ->limit(30)
            ->get()
            ->map(fn ($b) => [
                'id' => $b->id,
                'bill_number' => $b->bill_number,
                'vendor_name' => $b->vendor?->name ?? 'Unknown Vendor',
                'amount' => (string) $b->total_amount,
                'due_date' => $b->due_date?->toDateString(),
            ])->toArray();

        $payload = [
            'organization_id' => $organization->id,
            'pending_bills' => $bills,
        ];

        return $this->callAiWorkflow($organization, $user, 'invoice-approval-queue', $payload, 'workflow_invoice_approval');
    }

    /**
     * 5. AI Workflow: Draft Reconciliation Matches
     */
    public function workflowDraftReconciliationMatches(Organization $organization, User $user, string $bankAccountId): array
    {
        $transactions = \App\Domain\Banking\Models\BankTransaction::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->where('bank_account_id', $bankAccountId)
            ->where('reconciliation_status', 'unreconciled')
            ->limit(20)
            ->get()
            ->map(fn ($t) => [
                'id' => $t->id,
                'amount' => (string) $t->amount,
                'date' => $t->transaction_date->toDateString(),
                'description' => $t->description,
            ])->toArray();

        $candidateEntries = \App\Domain\Accounting\Journal\Models\JournalLine::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->limit(30)
            ->get()
            ->map(fn ($jl) => [
                'id' => $jl->id,
                'amount' => (string) ($jl->debit > 0 ? $jl->debit : $jl->credit),
                'description' => $jl->description,
            ])->toArray();

        $payload = [
            'organization_id' => $organization->id,
            'bank_account_id' => $bankAccountId,
            'bank_transactions' => $transactions,
            'candidate_ledger_entries' => $candidateEntries,
        ];

        return $this->callAiWorkflow($organization, $user, 'draft-reconciliation-matches', $payload, 'workflow_reconciliation');
    }

    /**
     * 6. AI Workflow: Find Missing Vendor Documents
     */
    public function workflowMissingVendorDocuments(Organization $organization, User $user): array
    {
        $bills = \App\Domain\Purchasing\Models\PurchaseBill::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->where('status', 'posted')
            ->whereDoesntHave('documents')
            ->with('vendor')
            ->limit(25)
            ->get()
            ->map(fn ($b) => [
                'id' => $b->id,
                'bill_number' => $b->bill_number,
                'vendor_name' => $b->vendor?->name ?? 'Direct Expense',
                'amount' => (string) $b->total_amount,
                'date' => $b->bill_date?->toDateString(),
                'has_attachment' => false,
            ])->toArray();

        $payload = [
            'organization_id' => $organization->id,
            'audit_bills' => $bills,
        ];

        return $this->callAiWorkflow($organization, $user, 'missing-vendor-documents', $payload, 'workflow_missing_docs');
    }

    /**
     * 7. AI Workflow: Prepare AR Collections Queue
     */
    public function workflowArCollectionsQueue(Organization $organization, User $user): array
    {
        $overdueInvoices = \App\Domain\Sales\Models\SalesInvoice::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->whereIn('status', ['posted', 'partially_paid'])
            ->where('due_date', '<', now()->toDateString())
            ->with('customer')
            ->limit(30)
            ->get()
            ->map(fn ($inv) => [
                'id' => $inv->id,
                'invoice_number' => $inv->invoice_number,
                'customer_name' => $inv->customer?->name ?? 'Customer',
                'amount_due' => (string) ($inv->total_amount - ($inv->amount_paid ?? 0)),
                'days_overdue' => max(1, (int) now()->diffInDays($inv->due_date)),
            ])->toArray();

        $payload = [
            'organization_id' => $organization->id,
            'overdue_invoices' => $overdueInvoices,
        ];

        return $this->callAiWorkflow($organization, $user, 'ar-collections-queue', $payload, 'workflow_ar_collections');
    }
}
