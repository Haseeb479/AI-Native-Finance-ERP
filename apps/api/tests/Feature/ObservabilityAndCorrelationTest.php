<?php

namespace Tests\Feature;

use App\Domain\AI\Services\AiGatewayService;
use App\Domain\Organization\Models\Organization;
use App\Logging\SensitiveDataRedactor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Monolog\Level;
use Monolog\LogRecord;
use Tests\TestCase;

class ObservabilityAndCorrelationTest extends TestCase
{
    use RefreshDatabase;

    public function test_incoming_request_receives_and_echoes_x_correlation_id(): void
    {
        $traceId = 'trace-uuid-abcdef-123456';

        $response = $this->withHeaders([
            'X-Correlation-ID' => $traceId,
        ])->getJson('/up');

        $response->assertHeader('X-Correlation-ID', $traceId);
        $this->assertEquals($traceId, Context::get('correlation_id'));
    }

    public function test_incoming_request_without_correlation_id_generates_uuid(): void
    {
        $response = $this->getJson('/up');

        $response->assertHeader('X-Correlation-ID');
        $correlationId = $response->headers->get('X-Correlation-ID');

        $this->assertNotEmpty($correlationId);
        $this->assertEquals($correlationId, Context::get('correlation_id'));
    }

    public function test_ai_gateway_service_propagates_correlation_id_to_downstream_calls(): void
    {
        $org = Organization::create([
            'name' => 'Trace Org',
            'legal_name' => 'Trace Org Legal',
            'base_currency' => 'PKR',
        ]);
        $user = User::factory()->create();

        $customTraceId = 'trace-distributed-copilot-999';
        Context::add('correlation_id', $customTraceId);

        Http::fake([
            '*classify/transaction*' => Http::response([
                'category' => 'office_supplies',
                'confidence' => 0.95,
                'usage_metadata' => ['prompt_tokens' => 50, 'completion_tokens' => 20],
            ], 200),
        ]);

        $gateway = app(AiGatewayService::class);
        $gateway->classifyTransaction($org, $user, 'Paper reams', 1200.0);

        Http::assertSent(function ($request) use ($customTraceId) {
            return $request->hasHeader('X-Correlation-ID', $customTraceId);
        });
    }

    public function test_sensitive_data_redactor_masks_credentials_and_secrets(): void
    {
        $redactor = new SensitiveDataRedactor();

        $context = [
            'user_email' => 'finance@corp.local',
            'password' => 'super_secret_password_123',
            'auth_token' => 'jwt.token.string',
            'api_key' => 'live_sk_999888',
            'authorization' => 'Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.dummy',
            'nested' => [
                'card_number' => '4111222233334444',
                'internal_service_secret' => 'top_secret_prod_key',
                'safe_field' => 'visible_amount_100',
            ],
        ];

        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'testing',
            level: Level::Info,
            message: 'User authentication logged',
            context: $context
        );

        $processed = $redactor($record);

        $this->assertEquals('[REDACTED]', $processed->context['password']);
        $this->assertEquals('[REDACTED]', $processed->context['auth_token']);
        $this->assertEquals('[REDACTED]', $processed->context['api_key']);
        $this->assertEquals('[REDACTED]', $processed->context['authorization']);
        $this->assertEquals('[REDACTED]', $processed->context['nested']['card_number']);
        $this->assertEquals('[REDACTED]', $processed->context['nested']['internal_service_secret']);
        $this->assertEquals('visible_amount_100', $processed->context['nested']['safe_field']);
    }
}
