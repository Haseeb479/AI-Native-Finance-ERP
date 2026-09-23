<?php

namespace Tests\Feature;

use App\Domain\Integrations\Models\Integration;
use App\Domain\Integrations\Models\Webhook;
use App\Domain\Integrations\Services\IntegrationManager;
use App\Domain\Integrations\Services\WebhookService;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Database\Seeders\AccountTypeSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IntegrationsAndWebhooksTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Organization $org;
    private string $ownerToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);
        $this->seed(AccountTypeSeeder::class);

        $this->owner = User::factory()->create([
            'name' => 'Tech Lead Zafar',
            'email' => 'tech@acme.pk',
        ]);
        $this->ownerToken = $this->owner->createToken('owner')->plainTextToken;

        $this->org = Organization::create([
            'name' => 'Apex Cloud Systems',
            'legal_name' => 'Apex Cloud Systems (Pvt) Ltd',
            'base_currency' => 'PKR',
            'fiscal_year_start_month' => 7,
        ]);
        $this->org->users()->attach($this->owner->id, ['role' => 'owner', 'is_default' => true]);
    }

    public function test_can_register_and_test_integration_connectors(): void
    {
        // 1. Register Stripe integration
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->ownerToken}",
            'X-Organization-Id' => $this->org->id,
        ])->postJson("/api/v1/organizations/{$this->org->id}/integrations", [
            'provider' => 'stripe',
            'name' => 'Production Stripe Gateway',
            'credentials' => [
                'api_key' => 'sk_live_test1234567890abcdef',
                'webhook_secret' => 'whsec_testSecretKey123',
            ],
            'settings' => [
                'currency' => 'USD',
                'auto_payout' => true,
            ],
        ]);

        $response->assertStatus(201);
        $integrationId = $response->json('data.id');
        $this->assertEquals('stripe', $response->json('data.provider'));

        // 2. Test Connection health
        $testRes = $this->withHeaders([
            'Authorization' => "Bearer {$this->ownerToken}",
            'X-Organization-Id' => $this->org->id,
        ])->postJson("/api/v1/organizations/{$this->org->id}/integrations/{$integrationId}/test");

        $testRes->assertStatus(200);
        $this->assertEquals('healthy', $testRes->json('data.status'));
    }

    public function test_integration_sync_log_recording_and_retry(): void
    {
        $manager = app(IntegrationManager::class);

        $integration = $manager->registerIntegration(
            $this->org,
            'hbl_bank',
            'HBL Corporate Open Banking Feed',
            ['client_id' => 'hbl_client_01', 'client_secret' => 'hbl_sec_999'],
            [],
            $this->owner
        );

        // Record a failed synchronization log
        $syncLog = $manager->recordSyncLog(
            $integration,
            'bank_feed.synced',
            'failed',
            ['statement_date' => '2025-08-01'],
            null,
            'Connection timed out while querying HBL Gateway API.'
        );

        $this->assertEquals('failed', $syncLog->status);
        $this->assertEquals(0, $syncLog->retry_count);
        $this->assertEquals('failed', $integration->fresh()->sync_status);

        // Retry the failed sync
        $retryRes = $this->withHeaders([
            'Authorization' => "Bearer {$this->ownerToken}",
            'X-Organization-Id' => $this->org->id,
        ])->postJson("/api/v1/organizations/{$this->org->id}/integrations/sync-logs/{$syncLog->id}/retry");

        $retryRes->assertStatus(200);
        $this->assertEquals('success', $retryRes->json('data.status'));
        $this->assertEquals(1, $retryRes->json('data.retry_count'));
        $this->assertEquals('success', $integration->fresh()->sync_status);
    }

    public function test_outbound_webhook_dispatch_and_hmac_sha256_generation(): void
    {
        $webhookService = app(WebhookService::class);

        // Register Webhook Endpoint
        $webhook = $webhookService->registerWebhook(
            $this->org,
            'https://webhook.site/test-endpoint',
            ['invoice.posted', 'payment.received'],
            'my_super_secret_signing_key_456',
            'Billing Webhook',
            $this->owner
        );

        $this->assertTrue($webhook->is_active);
        $this->assertEquals('my_super_secret_signing_key_456', $webhook->secret);

        // Dispatch Outbound Event
        $result = $webhookService->dispatchOutboundWebhook(
            $this->org,
            'invoice.posted',
            ['invoice_id' => 'INV-001', 'amount' => 50000.00]
        );

        $this->assertEquals(1, $result['dispatched_count']);
        $this->assertNotEmpty($result['deliveries']);

        $delivery = $result['deliveries'][0];
        $this->assertEquals('delivered', $delivery['status']);
        $this->assertStringStartsWith('sha256=', $delivery['signature']);

        // Verify that the generated HMAC signature matches expected value
        $deliveryRecord = \App\Domain\Integrations\Models\WebhookDelivery::find($delivery['delivery_id']);
        $expectedSignature = 'sha256=' . hash_hmac('sha256', json_encode($deliveryRecord->payload), 'my_super_secret_signing_key_456');
        $this->assertEquals($expectedSignature, $delivery['signature']);
    }

    public function test_inbound_webhook_hmac_signature_verification(): void
    {
        $webhookService = app(WebhookService::class);
        $secret = 'whsec_998877665544332211';

        $payload = json_encode(['event' => 'payment.intent.succeeded', 'amount' => 25000]);
        $validSignature = 'sha256=' . hash_hmac('sha256', $payload, $secret);

        // Verify Valid signature
        $isValid = $webhookService->verifyInboundSignature($payload, $validSignature, $secret);
        $this->assertTrue($isValid);

        // Verify Tampered payload rejected
        $tamperedPayload = json_encode(['event' => 'payment.intent.succeeded', 'amount' => 99999]);
        $isInvalid = $webhookService->verifyInboundSignature($tamperedPayload, $validSignature, $secret);
        $this->assertFalse($isInvalid);

        // Test through API endpoint
        $apiRes = $this->withHeaders([
            'Authorization' => "Bearer {$this->ownerToken}",
            'X-Organization-Id' => $this->org->id,
        ])->postJson("/api/v1/organizations/{$this->org->id}/webhooks/inbound-verify", [
            'payload' => $payload,
            'signature' => $validSignature,
            'secret' => $secret,
        ]);

        $apiRes->assertStatus(200);
        $this->assertTrue($apiRes->json('data.is_valid'));
    }
}
