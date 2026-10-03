<?php

namespace Tests\Feature;

use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Database\Seeders\AccountTypeSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProductionReadinessTest extends TestCase
{
    use RefreshDatabase;

    private User $adminUser;
    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);
        $this->seed(AccountTypeSeeder::class);

        $this->adminUser = User::factory()->create();
        $this->org = Organization::create([
            'name' => 'Readiness Test Org',
            'legal_name' => 'Readiness Test Org (Pvt) Ltd',
            'base_currency' => 'PKR',
        ]);
        $this->org->users()->attach($this->adminUser->id, ['role' => 'owner', 'is_default' => true]);

        // Clean any existing test-results artifact before each test
        $reportPath = storage_path('app/test-results.json');
        if (file_exists($reportPath)) {
            @unlink($reportPath);
        }
    }

    protected function tearDown(): void
    {
        $reportPath = storage_path('app/test-results.json');
        if (file_exists($reportPath)) {
            @unlink($reportPath);
        }

        parent::tearDown();
    }

    public function test_test_discovery_alone_cannot_produce_false_pass(): void
    {
        // Mock AI service as healthy
        Http::fake([
            '*/health' => Http::response(['status' => 'healthy', 'service' => 'AI Service'], 200),
        ]);

        $response = $this->getJson('/api/v1/health/production-readiness');

        $testSuite = $response->json('data.checks.test_suite');

        // Must NOT pass merely because test files exist on disk
        $this->assertFalse($testSuite['pass'], 'Test discovery alone must not produce a pass');
        $this->assertSame('unverified', $testSuite['status']);
        $this->assertTrue($testSuite['details']['is_diagnostic']);
        $this->assertFalse($testSuite['details']['execution_verified']);
        $this->assertGreaterThan(0, $testSuite['details']['test_files_count']);

        // Must fail the readiness gate (HTTP 503, ready=false)
        $response->assertStatus(503);
        $this->assertFalse($response->json('data.ready'));
        $this->assertSame('not_ready', $response->json('data.status'));
    }

    public function test_verified_test_execution_report_produces_pass(): void
    {
        Http::fake([
            '*/health' => Http::response(['status' => 'healthy', 'service' => 'AI Service'], 200),
        ]);

        // Create verified test execution report artifact
        $reportPath = storage_path('app/test-results.json');
        file_put_contents($reportPath, json_encode([
            'passed' => 65,
            'failed' => 0,
            'total' => 65,
            'timestamp' => now()->toIso8601String(),
        ]));

        $response = $this->getJson('/api/v1/health/production-readiness');

        $testSuite = $response->json('data.checks.test_suite');
        $this->assertTrue($testSuite['pass']);
        $this->assertSame('ok', $testSuite['status']);
        $this->assertTrue($testSuite['details']['execution_verified']);

        // All critical dependencies + verified test suite produce production_ready 200
        $response->assertStatus(200);
        $this->assertTrue($response->json('data.ready'));
        $this->assertSame('production_ready', $response->json('data.status'));
    }

    public function test_failed_tests_in_execution_report_causes_readiness_failure(): void
    {
        Http::fake([
            '*/health' => Http::response(['status' => 'healthy', 'service' => 'AI Service'], 200),
        ]);

        $reportPath = storage_path('app/test-results.json');
        file_put_contents($reportPath, json_encode([
            'passed' => 60,
            'failed' => 2,
            'total' => 62,
            'timestamp' => now()->toIso8601String(),
        ]));

        $response = $this->getJson('/api/v1/health/production-readiness');

        $response->assertStatus(503);
        $this->assertFalse($response->json('data.ready'));
        $this->assertSame('not_ready', $response->json('data.status'));

        $testSuite = $response->json('data.checks.test_suite');
        $this->assertFalse($testSuite['pass']);
        $this->assertSame('failed', $testSuite['status']);
        $this->assertFalse($testSuite['details']['execution_verified']);
    }

    public function test_failed_ai_service_produces_not_ready_status_and_503(): void
    {
        // Mock AI service as unavailable (500 or connection failure)
        Http::fake([
            '*/health' => Http::response(['error' => 'Internal Server Error'], 500),
        ]);

        $response = $this->getJson('/api/v1/health/production-readiness');

        $response->assertStatus(503);
        $this->assertFalse($response->json('data.ready'));
        $this->assertSame('not_ready', $response->json('data.status'));

        $aiCheck = $response->json('data.checks.ai_service');
        $this->assertFalse($aiCheck['pass']);
        $this->assertSame('unhealthy', $aiCheck['status']);
        $this->assertFalse($aiCheck['details']['verified']);
        $this->assertArrayNotHasKey('online', $aiCheck['details']);
    }

    public function test_ai_service_unavailable_on_connection_failure(): void
    {
        Http::fake([
            '*' => function () {
                throw new \Illuminate\Http\Client\ConnectionException('Connection refused');
            },
        ]);

        $response = $this->getJson('/api/v1/health/production-readiness');
        $this->assertSame('unavailable', $response->json('data.checks.ai_service.status'));
        $this->assertFalse($response->json('data.checks.ai_service.pass'));
    }

    public function test_ai_service_auth_failed_on_401(): void
    {
        Http::fake([
            '*' => Http::response(['detail' => 'Unauthorized'], 401),
        ]);

        $response = $this->getJson('/api/v1/health/production-readiness');
        $this->assertSame('auth_failed', $response->json('data.checks.ai_service.status'));
        $this->assertFalse($response->json('data.checks.ai_service.pass'));
    }

    public function test_ai_service_healthy_on_200(): void
    {
        Http::fake([
            '*' => Http::response(['status' => 'healthy'], 200),
        ]);

        $response = $this->getJson('/api/v1/health/production-readiness');
        $this->assertSame('healthy', $response->json('data.checks.ai_service.status'));
        $this->assertTrue($response->json('data.checks.ai_service.pass'));
    }

    public function test_sensitive_environment_and_db_information_is_not_exposed_publicly(): void
    {
        Http::fake([
            '*/health' => Http::response(['status' => 'healthy'], 200),
        ]);

        $response = $this->getJson('/api/v1/health/production-readiness');

        $data = $response->json('data');

        // 1. Must NOT leak php_version or laravel_version in public response
        $this->assertArrayNotHasKey('system_info', $data);

        // 2. Must NOT leak DB driver or version string publicly
        $this->assertArrayNotHasKey('driver', $data['checks']['database']['details']);
        $this->assertArrayNotHasKey('version', $data['checks']['database']['details']);

        // 3. Must NOT leak internal service URLs, latency, or online/secret flags publicly
        $this->assertArrayNotHasKey('service_url', $data['checks']['ai_service']['details']);
        $this->assertArrayNotHasKey('secret_configured', $data['checks']['ai_service']['details']);
        $this->assertArrayNotHasKey('online', $data['checks']['ai_service']['details']);
        $this->assertArrayNotHasKey('latency_ms', $data['checks']['ai_service']['details']);

        // 4. Must NOT leak env variables or app_debug flags publicly
        $this->assertArrayNotHasKey('app_debug', $data['checks']['environment']['details']);
        $this->assertArrayNotHasKey('app_env', $data['checks']['environment']['details']);
        $this->assertArrayNotHasKey('environment', $response->json('meta'));
    }

    public function test_authorized_admin_can_access_detailed_diagnostics(): void
    {
        Http::fake([
            '*/health' => Http::response(['status' => 'healthy'], 200),
        ]);

        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/v1/health/production-readiness');

        $data = $response->json('data');

        // Authorized response includes diagnostic system info
        $this->assertArrayHasKey('system_info', $data);
        $this->assertSame(PHP_VERSION, $data['system_info']['php_version']);
        $this->assertSame(app()->version(), $data['system_info']['laravel_version']);

        // Authorized response includes driver, version, and AI diagnostics
        $this->assertArrayHasKey('driver', $data['checks']['database']['details']);
        $this->assertArrayHasKey('service_url', $data['checks']['ai_service']['details']);
        $this->assertArrayHasKey('online', $data['checks']['ai_service']['details']);
        $this->assertArrayHasKey('latency_ms', $data['checks']['ai_service']['details']);
        $this->assertArrayHasKey('secret_configured', $data['checks']['ai_service']['details']);
        $this->assertArrayHasKey('environment', $response->json('meta'));
    }
}
