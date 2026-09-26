<?php

namespace Tests\Feature;

use App\Domain\AI\Models\AiRunLog;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Database\Seeders\AccountTypeSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiGatewayTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Organization $org;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);
        $this->seed(AccountTypeSeeder::class);

        $this->owner = User::factory()->create([
            'name' => 'Faisal Latif',
            'email' => 'faisal@innovate.pk',
        ]);
        $this->token = $this->owner->createToken('test')->plainTextToken;

        $this->org = Organization::create([
            'name' => 'Innovate Tech Labs',
            'legal_name' => 'Innovate Tech Labs (Pvt) Ltd',
            'base_currency' => 'PKR',
            'fiscal_year_start_month' => 7,
        ]);
        $this->org->users()->attach($this->owner->id, ['role' => 'owner', 'is_default' => true]);
    }

    public function test_ai_gateway_executes_classification_and_records_run_log(): void
    {
        Http::fake([
            '*classify/transaction*' => Http::response([
                'suggested_account' => [
                    'account_code' => '6050',
                    'account_name' => 'Software & Cloud Subscriptions',
                    'confidence' => 0.95,
                    'rationale' => 'AWS monthly hosting expense',
                    'tax_category' => 'sales_tax_exempt',
                ],
                'alternative_suggestions' => [],
                'requires_human_review' => false,
            ], 200),
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson("/api/v1/organizations/{$this->org->id}/ai/classify-transaction", [
                'description' => 'Amazon Web Services AWS EMEA Cloud Hosting',
                'amount' => 45000.00,
                'currency' => 'PKR',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.suggested_account.account_code', '6050')
            ->assertJsonPath('data.suggested_account.confidence', 0.95);

        // Verify audit run log was written
        $this->assertDatabaseHas('ai_run_logs', [
            'organization_id' => $this->org->id,
            'user_id' => $this->owner->id,
            'prompt_key' => 'classify_transaction',
            'status' => 'success',
        ]);

        $log = AiRunLog::where('organization_id', $this->org->id)->first();
        $this->assertNotNull($log);
        $this->assertGreaterThan(0, $log->input_tokens);
        $this->assertGreaterThan(0, $log->output_tokens);
        $this->assertGreaterThan(0, (float) $log->total_cost);
    }

    public function test_ai_usage_metrics_aggregation(): void
    {
        // Create 2 test logs directly
        AiRunLog::create([
            'organization_id' => $this->org->id,
            'user_id' => $this->owner->id,
            'prompt_key' => 'classify_transaction',
            'prompt_version' => 1,
            'provider' => 'gemini',
            'model' => 'gemini-1.5-flash',
            'input_tokens' => 200,
            'output_tokens' => 50,
            'total_cost' => 0.000018,
            'status' => 'success',
            'latency_ms' => 120,
        ]);

        AiRunLog::create([
            'organization_id' => $this->org->id,
            'user_id' => $this->owner->id,
            'prompt_key' => 'classify_transaction',
            'prompt_version' => 1,
            'provider' => 'gemini',
            'model' => 'gemini-1.5-flash',
            'input_tokens' => 300,
            'output_tokens' => 50,
            'total_cost' => 0.000026,
            'status' => 'success',
            'latency_ms' => 150,
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson("/api/v1/organizations/{$this->org->id}/ai/usage-metrics");

        $response->assertStatus(200)
            ->assertJsonPath('data.total_runs', 2)
            ->assertJsonPath('data.successful_runs', 2)
            ->assertJsonPath('data.total_input_tokens', 500)
            ->assertJsonPath('data.total_output_tokens', 100)
            ->assertJsonPath('data.total_tokens', 600);
    }

    public function test_ai_run_logs_endpoint(): void
    {
        AiRunLog::create([
            'organization_id' => $this->org->id,
            'user_id' => $this->owner->id,
            'prompt_key' => 'classify_transaction',
            'prompt_version' => 1,
            'provider' => 'gemini',
            'model' => 'gemini-1.5-flash',
            'input_tokens' => 100,
            'output_tokens' => 50,
            'total_cost' => 0.000011,
            'status' => 'success',
            'latency_ms' => 90,
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson("/api/v1/organizations/{$this->org->id}/ai/logs");

        $response->assertStatus(200)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.prompt_key', 'classify_transaction');
    }

    public function test_tenant_isolation_on_ai_gateway(): void
    {
        $intruder = User::factory()->create(['email' => 'hacker@evil.pk']);
        $intruderToken = $intruder->createToken('token')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$intruderToken}")
            ->getJson("/api/v1/organizations/{$this->org->id}/ai/usage-metrics");

        $response->assertStatus(404);
    }

    public function test_ai_gateway_generates_valid_signed_jwt_token(): void
    {
        $gatewayService = app(\App\Domain\AI\Services\AiGatewayService::class);
        $token = $gatewayService->generateInternalServiceToken($this->org, $this->owner);

        $this->assertNotEmpty($token);
        $parts = explode('.', $token);
        $this->assertCount(3, $parts, "JWT must consist of header, payload, and signature.");

        $header = json_decode(base64_decode(strtr($parts[0], '-_', '+/')), true);
        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);

        $this->assertEquals('HS256', $header['alg']);
        $this->assertEquals('laravel-finance-erp', $payload['iss']);
        $this->assertEquals('ai-tool-gateway', $payload['aud']);
        $this->assertEquals($this->org->id, $payload['organization_id']);
        $this->assertEquals($this->owner->id, $payload['user_id']);
        $this->assertNotEmpty($payload['jti']);
        $this->assertGreaterThan(time(), $payload['exp']);
    }

    public function test_ask_copilot_uses_server_authoritative_context(): void
    {
        Http::fake([
            '*copilot/qa*' => Http::response([
                'answer' => 'Your financial position is balanced.',
                'confidence' => 0.98,
                'referenced_accounts' => [],
                'evidence' => [],
                'caveats' => [],
                'flagged_for_review' => false,
            ], 200),
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson("/api/v1/organizations/{$this->org->id}/ai/copilot/qa", [
                'query' => 'What is our current financial health?',
                'financial_context' => [
                    'fake_bank_balance' => 9999999999.0, // Untrusted caller data
                ],
            ]);

        $response->assertStatus(200);

        Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
            $context = $request['financial_context'] ?? [];
            // Verify server-authoritative fields were sent
            return isset($context['is_trial_balance_balanced'])
                && isset($context['total_debit'])
                && isset($context['total_credit'])
                && $request->hasHeader('Authorization');
        });
    }

    public function test_ai_gateway_records_provider_reported_metrics_and_request_id(): void
    {
        Http::fake([
            '*classify/transaction*' => Http::response([
                'suggested_account' => [
                    'account_code' => '6010',
                    'account_name' => 'IT Software',
                    'confidence' => 0.99,
                    'rationale' => 'SaaS subscription',
                ],
                'usage_metadata' => [
                    'prompt_tokens' => 142,
                    'completion_tokens' => 48,
                    'cached_tokens' => 16,
                    'request_id' => 'req-gemini-prod-9921',
                    'actual_cost' => 0.000021,
                ],
            ], 200),
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson("/api/v1/organizations/{$this->org->id}/ai/classify-transaction", [
                'description' => 'GitHub Enterprise licenses',
                'amount' => 12000.00,
                'currency' => 'PKR',
            ]);

        $response->assertStatus(200);

        $log = AiRunLog::where('organization_id', $this->org->id)
            ->where('prompt_key', 'classify_transaction')
            ->latest()
            ->first();

        $this->assertNotNull($log);
        $this->assertEquals(142, $log->input_tokens);
        $this->assertEquals(48, $log->output_tokens);
        $this->assertEquals(16, $log->cached_tokens);
        $this->assertEquals('req-gemini-prod-9921', $log->provider_request_id);
        $this->assertEquals(0.000021, (float) $log->total_cost);
    }

    public function test_ai_gateway_blocks_requests_when_quota_exceeded(): void
    {
        // Set quota to exhausted
        \App\Domain\AI\Models\AiQuota::create([
            'organization_id' => $this->org->id,
            'feature' => 'classify_transaction',
            'monthly_token_quota' => 100,
            'monthly_spend_quota' => 0.0100,
            'tokens_used_this_month' => 150, // Exceeded
            'spend_used_this_month' => 0.0200,
            'hard_limit_enabled' => true,
            'soft_alert_threshold_percent' => 80,
            'last_reset_date' => now()->toDateString(),
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson("/api/v1/organizations/{$this->org->id}/ai/classify-transaction", [
                'description' => 'Should be blocked by quota limiter',
                'amount' => 5000.00,
                'currency' => 'PKR',
            ]);

        // Expect 502 with AiQuotaExceededException envelope
        $response->assertStatus(502)
            ->assertJsonPath('errors.0.code', 'AI_EXECUTION_ERROR');
        $this->assertStringContainsString('exceeded its monthly AI quota', $response->json('errors.0.message'));
    }
}

