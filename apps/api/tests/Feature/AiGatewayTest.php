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
}
