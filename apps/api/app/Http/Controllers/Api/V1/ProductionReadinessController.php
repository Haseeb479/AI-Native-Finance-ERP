<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Production Readiness Controller (P0 Hardened)
 *
 * Provides a structured health and readiness endpoint for deployment
 * verification, ops monitoring, and CI/CD gate checks.
 *
 * Security: Does not leak internal topography, credentials, or sensitive env vars.
 * Reliability: Requires true dependency health — does not fake passes.
 *
 * GET /api/v1/health/production-readiness
 */
class ProductionReadinessController extends Controller
{
    public function check(Request $request): JsonResponse
    {
        $isAuthorized = $this->isAuthorizedDiagnostic($request);
        $checks = [];

        // ── 1. Database Connectivity ──────────────────────────────────────────
        $checks['database'] = $this->checkDatabase($isAuthorized);

        // ── 2. Critical Tables Exist ─────────────────────────────────────────
        $checks['critical_tables'] = $this->checkCriticalTables($isAuthorized);

        // ── 3. Pending Migrations ─────────────────────────────────────────────
        $checks['migrations'] = $this->checkMigrations($isAuthorized);

        // ── 4. Cache System ───────────────────────────────────────────────────
        $checks['cache'] = $this->checkCache($isAuthorized);

        // ── 5. Environment Checks ─────────────────────────────────────────────
        $checks['environment'] = $this->checkEnvironment($isAuthorized);

        // ── 6. Required Config Values ─────────────────────────────────────────
        $checks['configuration'] = $this->checkConfiguration($isAuthorized);

        // ── 7. Accounting Modules Online ──────────────────────────────────────
        $checks['accounting_modules'] = $this->checkAccountingModules($isAuthorized);

        // ── 8. AI Service & Internal Security (Honest Readiness) ──────────────
        $checks['ai_service'] = $this->checkAiService($isAuthorized);

        // ── 9. Test Suite Execution & Diagnostics (No False Pass) ─────────────
        $checks['test_suite'] = $this->checkTestSuite($isAuthorized);

        // Compute authoritative readiness: all critical dependencies AND verified test execution must pass
        $criticalDependencies = [
            'database',
            'critical_tables',
            'migrations',
            'cache',
            'environment',
            'configuration',
            'accounting_modules',
            'ai_service',
            'test_suite',
        ];

        $allPass = true;
        foreach ($criticalDependencies as $depKey) {
            if (! ($checks[$depKey]['pass'] ?? false)) {
                $allPass = false;
            }
        }

        $statusCode = $allPass ? 200 : 503;
        $status     = $allPass ? 'production_ready' : 'not_ready';

        $data = [
            'status'          => $status,
            'ready'           => $allPass,
            'total_checks'    => count($checks),
            'passing_checks'  => collect($checks)->where('pass', true)->count(),
            'failing_checks'  => collect($checks)->where('pass', false)->count(),
            'checks'          => $checks,
            'version'         => 'v1.0.0',
        ];

        // Detailed system information restricted to authorized operators
        if ($isAuthorized) {
            $data['system_info'] = [
                'app_name'        => config('app.name'),
                'app_env'         => config('app.env'),
                'app_version'     => 'v1.0.0',
                'php_version'     => PHP_VERSION,
                'laravel_version' => app()->version(),
            ];
        }

        return response()->json([
            'data' => $data,
            'meta' => [
                'timestamp'   => now()->toIso8601String(),
                'environment' => config('app.env'),
            ],
            'errors' => [],
        ], $statusCode);
    }

    private function checkDatabase(bool $isAuthorized): array
    {
        try {
            DB::connection()->getPdo();

            $details = [
                'connection' => 'established',
            ];

            if ($isAuthorized) {
                $details['driver'] = DB::connection()->getDriverName();
            }

            return [
                'pass'        => true,
                'status'      => 'ok',
                'description' => 'Database connectivity',
                'details'     => $details,
            ];
        } catch (Throwable $e) {
            $details = [
                'message' => 'Database connectivity unavailable',
            ];

            if ($isAuthorized) {
                $details['error'] = $e->getMessage();
            }

            return [
                'pass'        => false,
                'status'      => 'error',
                'description' => 'Database connectivity',
                'details'     => $details,
            ];
        }
    }

    private function checkCriticalTables(bool $isAuthorized): array
    {
        $requiredTables = [
            'users',
            'organizations',
            'accounts',
            'account_types',
            'journal_entries',
            'journal_lines',
            'accounting_periods',
            'sales_invoices',
            'sales_invoice_lines',
            'customers',
            'purchase_bills',
            'vendors',
            'bank_accounts',
            'bank_transactions',
            'products',
            'warehouses',
            'warehouse_stock',
            'purchase_orders',
            'purchase_requisitions',
            'webhooks',
            'security_api_keys',
            'security_events',
        ];

        $missing = [];
        foreach ($requiredTables as $table) {
            if (! Schema::hasTable($table)) {
                $missing[] = $table;
            }
        }

        $pass = empty($missing);
        $details = [
            'required_count' => count($requiredTables),
            'verified'       => $pass,
        ];

        if (! $pass || $isAuthorized) {
            $details['missing'] = $missing;
        }

        return [
            'pass'        => $pass,
            'status'      => $pass ? 'ok' : 'error',
            'description' => 'Critical database tables',
            'details'     => $details,
        ];
    }

    private function checkMigrations(bool $isAuthorized): array
    {
        try {
            Artisan::call('migrate:status', ['--no-interaction' => true]);
            $output = Artisan::output();

            $hasPending = str_contains($output, 'Pending');

            return [
                'pass'        => ! $hasPending,
                'status'      => $hasPending ? 'warning' : 'ok',
                'description' => 'Database migrations',
                'details'     => [
                    'pending_migrations' => $hasPending,
                    'note'               => $hasPending
                        ? 'Pending migrations detected'
                        : 'All migrations applied',
                ],
            ];
        } catch (Throwable $e) {
            $details = ['message' => 'Unable to query migration status'];
            if ($isAuthorized) {
                $details['error'] = $e->getMessage();
            }

            return [
                'pass'        => false,
                'status'      => 'error',
                'description' => 'Database migrations',
                'details'     => $details,
            ];
        }
    }

    private function checkCache(bool $isAuthorized): array
    {
        try {
            $key   = 'production_readiness_check_' . now()->timestamp . '_' . bin2hex(random_bytes(4));
            $value = 'cache_verified_' . rand(1000, 9999);

            Cache::put($key, $value, 10);
            $retrieved = Cache::get($key);
            Cache::forget($key);

            $cacheOk = ($retrieved === $value);

            $details = [
                'verified' => $cacheOk,
            ];

            if ($isAuthorized) {
                $details['driver'] = config('cache.default');
            }

            return [
                'pass'        => $cacheOk,
                'status'      => $cacheOk ? 'ok' : 'error',
                'description' => 'Cache system',
                'details'     => $details,
            ];
        } catch (Throwable $e) {
            $details = ['message' => 'Cache operation failed'];
            if ($isAuthorized) {
                $details['error'] = $e->getMessage();
            }

            return [
                'pass'        => false,
                'status'      => 'error',
                'description' => 'Cache system',
                'details'     => $details,
            ];
        }
    }

    private function checkEnvironment(bool $isAuthorized): array
    {
        $env    = config('app.env');
        $debug  = config('app.debug');
        $issues = [];

        if ($env === 'production' && $debug) {
            $issues[] = 'APP_DEBUG must be false in production';
        }

        if (empty(config('app.key'))) {
            $issues[] = 'APP_KEY is not configured';
        }

        $pass = empty($issues);

        $details = [
            'status' => $pass ? 'verified' : 'invalid_configuration',
        ];

        if ($isAuthorized) {
            $details['app_env']   = $env;
            $details['app_debug'] = $debug;
            $details['issues']    = $issues;
        }

        return [
            'pass'        => $pass,
            'status'      => $pass ? 'ok' : 'error',
            'description' => 'Application environment',
            'details'     => $details,
        ];
    }

    private function checkConfiguration(bool $isAuthorized): array
    {
        $requiredKeys = [
            'app.key'             => config('app.key'),
            'database.default'    => config('database.default'),
            'auth.guards.sanctum' => config('auth.guards.sanctum') !== null,
        ];

        $missing = [];
        foreach ($requiredKeys as $key => $value) {
            if (empty($value)) {
                $missing[] = $key;
            }
        }

        $pass = empty($missing);
        $details = [
            'verified' => $pass,
        ];

        if (! $pass && $isAuthorized) {
            $details['missing_keys'] = $missing;
        }

        return [
            'pass'        => $pass,
            'status'      => $pass ? 'ok' : 'warning',
            'description' => 'Required configuration values',
            'details'     => $details,
        ];
    }

    private function checkAccountingModules(bool $isAuthorized): array
    {
        $servicesToCheck = [
            'PostingEngine'         => \App\Domain\Accounting\Posting\Services\PostingEngine::class,
            'PeriodManager'         => \App\Domain\Accounting\Period\Services\PeriodManager::class,
            'InvoiceService'        => \App\Domain\Sales\Services\InvoiceService::class,
            'BillService'           => \App\Domain\Purchasing\Services\BillService::class,
            'BankingReconciliation' => \App\Domain\Banking\Services\ReconciliationService::class,
            'InventoryService'      => \App\Domain\Inventory\Services\InventoryService::class,
            'CogsEngine'            => \App\Domain\Inventory\Services\CogsEngine::class,
            'ProcurementService'    => \App\Domain\Procurement\Services\ProcurementService::class,
            'SecurityService'       => \App\Domain\Security\Services\SecurityService::class,
            'IntegrationManager'    => \App\Domain\Integrations\Services\IntegrationManager::class,
        ];

        $failed = [];
        foreach ($servicesToCheck as $name => $class) {
            try {
                app($class);
            } catch (Throwable) {
                $failed[] = $name;
            }
        }

        $pass = empty($failed);
        $details = [
            'total'   => count($servicesToCheck),
            'online'  => count($servicesToCheck) - count($failed),
            'healthy' => $pass,
        ];

        if (! $pass && $isAuthorized) {
            $details['failed_modules'] = $failed;
        }

        return [
            'pass'        => $pass,
            'status'      => $pass ? 'ok' : 'error',
            'description' => 'Accounting & domain modules',
            'details'     => $details,
        ];
    }

    private function checkAiService(bool $isAuthorized): array
    {
        $url    = config('services.ai.url', 'http://localhost:8001');
        $secret = config('services.ai.internal_secret');
        $env    = config('app.env');

        if (empty($url)) {
            return [
                'pass'        => false,
                'status'      => 'not_checked',
                'description' => 'AI Service & Security Configuration',
                'details'     => [
                    'online'  => false,
                    'status'  => 'not_checked',
                    'message' => 'AI service URL is not configured',
                ],
            ];
        }

        $issues = [];
        if ($env === 'production' && ($secret === 'ai-native-finance-erp-internal-service-secret-key' || empty($secret))) {
            $issues[] = 'Production environment cannot use default or empty AI_INTERNAL_SECRET';
        }

        $status    = 'unavailable';
        $online    = false;
        $latencyMs = null;

        try {
            $start = microtime(true);
            $response = Http::timeout(2)->get("{$url}/health");
            $latencyMs = round((microtime(true) - $start) * 1000, 2);

            if ($response->successful()) {
                $data = $response->json();
                $svcStatus = $data['status'] ?? 'unknown';

                if ($svcStatus === 'healthy' || $svcStatus === 'ok') {
                    if ($latencyMs > 3000 || ! empty($issues)) {
                        $status = 'degraded';
                    } else {
                        $status = 'healthy';
                    }
                    $online = true;
                } else {
                    $status = 'degraded';
                    $online = true;
                }
            } elseif ($response->status() === 401 || $response->status() === 403) {
                $status = 'auth_failed';
                $online = false;
            } else {
                $status = 'unhealthy';
                $online = false;
            }
        } catch (\Illuminate\Http\Client\ConnectionException) {
            $status = 'unavailable';
            $online = false;
        } catch (Throwable) {
            $status = 'unavailable';
            $online = false;
        }

        $pass = ($status === 'healthy' && empty($issues));

        $details = [
            'online' => $online,
            'status' => $status,
        ];

        if ($latencyMs !== null) {
            $details['latency_ms'] = $latencyMs;
        }

        if ($isAuthorized) {
            $details['service_url']        = $url;
            $details['secret_configured']  = ! empty($secret) && $secret !== 'ai-native-finance-erp-internal-service-secret-key';
            $details['issues']             = $issues;
        }

        return [
            'pass'        => $pass,
            'status'      => $status,
            'description' => 'AI Service & Security Configuration',
            'details'     => $details,
        ];
    }

    private function checkTestSuite(bool $isAuthorized): array
    {
        $unitFiles    = glob(base_path('tests/Unit/*Test.php')) ?: [];
        $featureFiles = glob(base_path('tests/Feature/*Test.php')) ?: [];
        $testFiles    = array_merge($unitFiles, $featureFiles);

        $totalTestMethods = 0;
        foreach ($testFiles as $file) {
            $content = @file_get_contents($file);
            if ($content) {
                $totalTestMethods += preg_match_all('/public\s+function\s+test_/i', $content);
            }
        }

        // Check for verified test execution report
        $reportPath = storage_path('app/test-results.json');
        $hasReport  = file_exists($reportPath);
        $reportData = $hasReport ? json_decode(@file_get_contents($reportPath), true) : null;

        $executionVerified = false;
        $status = 'unverified';
        $pass   = false;

        if ($hasReport && is_array($reportData)) {
            $failed = $reportData['failed'] ?? null;
            $passed = $reportData['passed'] ?? 0;
            $total  = $reportData['total'] ?? 0;

            if ($failed === 0 && $passed > 0) {
                $executionVerified = true;
                $status = 'ok';
                $pass   = true;
            } else {
                $executionVerified = false;
                $status = 'failed';
                $pass   = false;
            }
        } else {
            // Test discovery alone cannot produce a false pass. Must have verified passing test execution.
            $executionVerified = false;
            $status = 'unverified';
            $pass   = false;
        }

        $details = [
            'test_files_count'        => count($testFiles),
            'discovered_test_methods' => $totalTestMethods,
            'is_diagnostic'           => true,
            'execution_verified'      => $executionVerified,
            'note'                    => $executionVerified
                ? 'Test suite execution verified with passing results.'
                : 'Unverified: Test files exist on disk, but test suite execution has not been verified. File discovery alone does not prove production readiness.',
        ];

        if ($isAuthorized && $hasReport) {
            $details['last_execution'] = $reportData;
        }

        return [
            'pass'        => $pass,
            'status'      => $status,
            'description' => 'Automated test suite execution verification',
            'details'     => $details,
        ];
    }

    private function isAuthorizedDiagnostic(Request $request): bool
    {
        // 1. Authenticated user with admin/owner role in any organization
        if ($user = $request->user()) {
            try {
                if ($user->organizations()->whereIn('organization_user.role', ['owner', 'admin'])->exists()) {
                    return true;
                }
            } catch (Throwable) {
                // fall through if relation not loaded
            }
        }

        // 2. Authoritative internal service token check
        $authHeader = $request->header('Authorization', '');
        if (str_starts_with($authHeader, 'Bearer ')) {
            $token = substr($authHeader, 7);
            try {
                $secret = config('services.ai.internal_secret');
                if (! empty($secret)) {
                    $parts = explode('.', $token);
                    if (count($parts) === 3) {
                        [$headB64, $bodyB64, $cryptoB64] = $parts;
                        $expectedSig = hash_hmac('sha256', "{$headB64}.{$bodyB64}", $secret);
                        if (hash_equals($expectedSig, $cryptoB64)) {
                            $payload = json_decode(base64_decode(strtr($bodyB64, '-_', '+/')), true);
                            if (isset($payload['exp']) && $payload['exp'] >= time()) {
                                return true;
                            }
                        }
                    }
                }
            } catch (Throwable) {
                // fall through
            }
        }

        return false;
    }
}
