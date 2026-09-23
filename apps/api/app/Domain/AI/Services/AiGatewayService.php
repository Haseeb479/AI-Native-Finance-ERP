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
