<?php

namespace Tests\Feature;

use App\Domain\Organization\Models\Organization;
use App\Domain\Security\Models\SecurityApiKey;
use App\Domain\Security\Models\SecurityEvent;
use App\Domain\Security\Services\SecurityService;
use App\Models\User;
use Database\Seeders\AccountTypeSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class SecurityHardeningAndRateLimitingTest extends TestCase
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
            'name' => 'Chief InfoSec Officer Hamza',
            'email' => 'ciso@securetech.pk',
        ]);
        $this->ownerToken = $this->owner->createToken('owner')->plainTextToken;

        $this->org = Organization::create([
            'name' => 'CyberFin Fortress',
            'legal_name' => 'CyberFin Fortress Technologies (Pvt) Ltd',
            'base_currency' => 'PKR',
            'fiscal_year_start_month' => 7,
        ]);
        $this->org->users()->attach($this->owner->id, ['role' => 'owner', 'is_default' => true]);
    }

    public function test_can_create_and_verify_organization_api_key(): void
    {
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->ownerToken}",
            'X-Organization-Id' => $this->org->id,
        ])->postJson("/api/v1/organizations/{$this->org->id}/security/api-keys", [
            'name' => 'Automated ERP Ingestion Pipeline',
            'rate_limit_per_minute' => 100,
        ]);

        $response->assertStatus(201);
        $plainKey = $response->json('data.plain_key');
        $keyId = $response->json('data.api_key.id');

        $this->assertStringStartsWith('erp_live_', $plainKey);

        // Verify that raw plain key is NOT stored directly in database, but hashed
        $dbKey = SecurityApiKey::findOrFail($keyId);
        $this->assertEquals(hash('sha256', $plainKey), $dbKey->key_hash);

        // Verify API key lookup
        $securityService = app(SecurityService::class);
        $verified = $securityService->verifyApiKey($plainKey);
        $this->assertNotNull($verified);
        $this->assertEquals($keyId, $verified->id);
    }

    public function test_api_key_secret_rotation(): void
    {
        $securityService = app(SecurityService::class);

        // 1. Create key
        $created = $securityService->createApiKey($this->org, 'Payment Gateway Connector', [], 60, $this->owner);
        $oldPlainKey = $created['plain_key'];
        $keyRecord = $created['key'];

        $this->assertNotNull($securityService->verifyApiKey($oldPlainKey));

        // 2. Rotate secret via API
        $rotateRes = $this->withHeaders([
            'Authorization' => "Bearer {$this->ownerToken}",
            'X-Organization-Id' => $this->org->id,
        ])->postJson("/api/v1/organizations/{$this->org->id}/security/api-keys/{$keyRecord->id}/rotate");

        $rotateRes->assertStatus(200);
        $newPlainKey = $rotateRes->json('data.new_plain_key');
        $this->assertNotEquals($oldPlainKey, $newPlainKey);

        // 3. Old key must now be invalid, new key must be valid
        $this->assertNull($securityService->verifyApiKey($oldPlainKey));
        $this->assertNotNull($securityService->verifyApiKey($newPlainKey));

        // 4. Verify rotation audit event
        $event = SecurityEvent::where('organization_id', $this->org->id)
            ->where('event_type', 'secret_rotated')
            ->first();
        $this->assertNotNull($event);
        $this->assertEquals('warning', $event->severity);
    }

    public function test_revoked_api_key_rejection(): void
    {
        $securityService = app(SecurityService::class);
        $created = $securityService->createApiKey($this->org, 'Temporary Worker Key', [], 60, $this->owner);
        $plainKey = $created['plain_key'];
        $key = $created['key'];

        // Revoke key
        $delRes = $this->withHeaders([
            'Authorization' => "Bearer {$this->ownerToken}",
            'X-Organization-Id' => $this->org->id,
        ])->deleteJson("/api/v1/organizations/{$this->org->id}/security/api-keys/{$key->id}");

        $delRes->assertStatus(200);
        $this->assertFalse($key->fresh()->is_active);
        $this->assertNotNull($key->fresh()->revoked_at);

        // Verification must return null
        $this->assertNull($securityService->verifyApiKey($plainKey));
    }

    public function test_rate_limiter_allows_and_throttles_excess_requests(): void
    {
        Cache::flush();
        $securityService = app(SecurityService::class);
        $identifier = 'test_client_ip_192.168.1.50';

        // Limit: 3 requests per minute
        $r1 = $securityService->checkRateLimit($identifier, 3, 60);
        $this->assertTrue($r1['allowed']);
        $this->assertEquals(2, $r1['remaining']);

        $r2 = $securityService->checkRateLimit($identifier, 3, 60);
        $this->assertTrue($r2['allowed']);
        $this->assertEquals(1, $r2['remaining']);

        $r3 = $securityService->checkRateLimit($identifier, 3, 60);
        $this->assertTrue($r3['allowed']);
        $this->assertEquals(0, $r3['remaining']);

        // 4th request must be throttled
        $r4 = $securityService->checkRateLimit($identifier, 3, 60);
        $this->assertFalse($r4['allowed']);
        $this->assertEquals(0, $r4['remaining']);
        $this->assertGreaterThan(0, $r4['retry_after']);
    }

    public function test_strict_tenant_isolation_detection_and_security_event_logging(): void
    {
        // Create an external intruder from Org B
        $intruder = User::factory()->create([
            'name' => 'Mallory External',
            'email' => 'mallory@evilcorp.pk',
        ]);
        $intruderToken = $intruder->createToken('intruder')->plainTextToken;

        $orgB = Organization::create([
            'name' => 'Foreign Org B',
            'legal_name' => 'Foreign Organization B Ltd',
            'base_currency' => 'PKR',
        ]);
        $orgB->users()->attach($intruder->id, ['role' => 'owner', 'is_default' => true]);

        // Intruder attempts to verify access on Org A (CyberFin Fortress)
        $attackRes = $this->withHeaders([
            'Authorization' => "Bearer {$intruderToken}",
            'X-Organization-Id' => $this->org->id,
        ])->postJson("/api/v1/organizations/{$this->org->id}/security/tenant-check");

        $attackRes->assertStatus(403);
        $this->assertEquals('TENANT_ISOLATION_VIOLATION', $attackRes->json('errors.0.code'));

        // Verify that critical breach security event was logged
        $breachEvent = SecurityEvent::where('organization_id', $this->org->id)
            ->where('event_type', 'unauthorized_tenant_access')
            ->first();

        $this->assertNotNull($breachEvent);
        $this->assertEquals('critical', $breachEvent->severity);
        $this->assertEquals($intruder->id, $breachEvent->user_id);
    }
}
