<?php

namespace App\Domain\AI\Services;

use App\Domain\AI\Models\AiRunLog;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AiGatewayService
{
    private string $aiServiceUrl;

    public function __construct(private readonly PromptRegistry $promptRegistry)
    {
        $this->aiServiceUrl = config('services.ai.url', 'http://localhost:8001');
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
        $inputTokens = (int) (strlen($description) / 4) + 60; // Approximate token estimation
        $outputTokens = 40;
        $costPer1k = 0.000075; // Gemini Flash pricing approx
        $totalCost = (($inputTokens + $outputTokens) / 1000) * $costPer1k;

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

            AiRunLog::create([
                'organization_id' => $organization->id,
                'user_id' => $user->id,
                'prompt_key' => 'classify_transaction',
                'prompt_version' => $promptInfo['version'],
                'provider' => 'gemini',
                'model' => $promptInfo['model'],
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
                'total_cost' => round($totalCost, 6),
                'status' => $status,
                'latency_ms' => $latencyMs,
                'error_message' => $errorMessage,
                'metadata' => [
                    'amount' => $amount,
                    'currency' => $currency,
                ],
            ]);
        }

        return $responseBody;
    }

    /**
     * Financial Q&A through Copilot with audit logging.
     */
    public function askCopilot(Organization $organization, User $user, string $query, array $context = []): array
    {
        $startTime = microtime(true);
        $promptInfo = $this->promptRegistry->getTemplate('financial_qa', $organization);

        $endpoint = "{$this->aiServiceUrl}/v1/copilot/qa";
        $payload = [
            'query' => $query,
            'organization_id' => $organization->id,
            'currency' => $organization->base_currency ?? 'PKR',
            'financial_context' => $context,
        ];

        $status = 'success';
        $errorMessage = null;
        $responseBody = [];
        $inputTokens = (int) (strlen($query) / 4) + 120;
        $outputTokens = 90;
        $totalCost = (($inputTokens + $outputTokens) / 1000) * 0.000075;

        try {
            $response = Http::timeout(20)->post($endpoint, $payload);
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
            AiRunLog::create([
                'organization_id' => $organization->id,
                'user_id' => $user->id,
                'prompt_key' => 'financial_qa',
                'prompt_version' => $promptInfo['version'],
                'provider' => 'gemini',
                'model' => $promptInfo['model'],
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
                'total_cost' => round($totalCost, 6),
                'status' => $status,
                'latency_ms' => $latencyMs,
                'error_message' => $errorMessage,
                'metadata' => ['query' => substr($query, 0, 100)],
            ]);
        }

        return $responseBody;
    }

    /**
     * AI balanced double-entry journal draft proposition with audit logging.
     */
    public function draftJournal(Organization $organization, User $user, string $instruction, ?float $amount = null): array
    {
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
        $inputTokens = (int) (strlen($instruction) / 4) + 100;
        $outputTokens = 120;
        $totalCost = (($inputTokens + $outputTokens) / 1000) * 0.000075;

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
            AiRunLog::create([
                'organization_id' => $organization->id,
                'user_id' => $user->id,
                'prompt_key' => 'journal_draft',
                'prompt_version' => $promptInfo['version'],
                'provider' => 'gemini',
                'model' => $promptInfo['model'],
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
                'total_cost' => round($totalCost, 6),
                'status' => $status,
                'latency_ms' => $latencyMs,
                'error_message' => $errorMessage,
                'metadata' => ['amount' => $amount],
            ]);
        }

        return $responseBody;
    }

    /**
     * AI Report explanation & variance analysis with audit logging.
     */
    public function explainReport(Organization $organization, User $user, string $reportType, array $reportData, string $periodLabel = 'Current Period'): array
    {
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
        $inputTokens = 250;
        $outputTokens = 180;
        $totalCost = (($inputTokens + $outputTokens) / 1000) * 0.000075;

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
            AiRunLog::create([
                'organization_id' => $organization->id,
                'user_id' => $user->id,
                'prompt_key' => 'explain_report',
                'prompt_version' => $promptInfo['version'],
                'provider' => 'gemini',
                'model' => $promptInfo['model'],
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
                'total_cost' => round($totalCost, 6),
                'status' => $status,
                'latency_ms' => $latencyMs,
                'error_message' => $errorMessage,
                'metadata' => ['report_type' => $reportType, 'period' => $periodLabel],
            ]);
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
}
