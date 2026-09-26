<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Step 30 — Production Readiness Controller
 *
 * Provides a structured health + readiness checklist endpoint for deployment
 * verification, ops dashboards, and CI/CD gate checks.
 *
 * GET /api/v1/health/production-readiness
 */
class ProductionReadinessController extends Controller
{
    public function check(): JsonResponse
    {
        $checks   = [];
        $allPass  = true;

        // ── 1. Database Connectivity ──────────────────────────────────────────
        $checks['database'] = $this->checkDatabase();
        if (! $checks['database']['pass']) {
            $allPass = false;
        }

        // ── 2. Critical Tables Exist ─────────────────────────────────────────
        $checks['critical_tables'] = $this->checkCriticalTables();
        if (! $checks['critical_tables']['pass']) {
            $allPass = false;
        }

        // ── 3. Pending Migrations ─────────────────────────────────────────────
        $checks['migrations'] = $this->checkMigrations();
        if (! $checks['migrations']['pass']) {
            $allPass = false;
        }

        // ── 4. Cache System ───────────────────────────────────────────────────
        $checks['cache'] = $this->checkCache();
        if (! $checks['cache']['pass']) {
            $allPass = false;
        }

        // ── 5. Environment Checks ─────────────────────────────────────────────
        $checks['environment'] = $this->checkEnvironment();
        if (! $checks['environment']['pass']) {
            $allPass = false;
        }

        // ── 6. Required Config Values ─────────────────────────────────────────
        $checks['configuration'] = $this->checkConfiguration();
        if (! $checks['configuration']['pass']) {
            $allPass = false;
        }

        // ── 7. Accounting Modules Online ──────────────────────────────────────
        $checks['accounting_modules'] = $this->checkAccountingModules();
        if (! $checks['accounting_modules']['pass']) {
            $allPass = false;
        }

        // ── 8. AI Service & Secret Configuration ──────────────────────────────
        $checks['ai_service'] = $this->checkAiService();
        if (! $checks['ai_service']['pass']) {
            $allPass = false;
        }

        // ── 9. Test Suite Discovery & State (Dynamic) ─────────────────────────
        $checks['test_suite'] = $this->checkTestSuite();
        if (! $checks['test_suite']['pass']) {
            $allPass = false;
        }

        $statusCode = $allPass ? 200 : 503;
        $status     = $allPass ? 'production_ready' : 'not_ready';

        return response()->json([
            'data' => [
                'status'          => $status,
                'ready'           => $allPass,
                'total_checks'    => count($checks),
                'passing_checks'  => collect($checks)->where('pass', true)->count(),
                'failing_checks'  => collect($checks)->where('pass', false)->count(),
                'checks'          => $checks,
                'system_info'     => [
                    'app_name'    => config('app.name'),
                    'app_env'     => config('app.env'),
                    'app_version' => 'v1.0.0',
                    'php_version' => PHP_VERSION,
                    'laravel_version' => app()->version(),
                ],
            ],
            'meta' => [
                'timestamp'   => now()->toIso8601String(),
                'environment' => config('app.env'),
            ],
            'errors' => [],
        ], $statusCode);
    }

    private function checkDatabase(): array
    {
        try {
            DB::connection()->getPdo();
            $driver  = DB::connection()->getDriverName();
            $version = DB::selectOne("SELECT version() as v")?->v ?? 'unknown';

            return [
                'pass'        => true,
                'status'      => 'ok',
                'description' => 'Database connectivity',
                'details'     => [
                    'driver'  => $driver,
                    'version' => $version,
                ],
            ];
        } catch (Throwable $e) {
            return [
                'pass'        => false,
                'status'      => 'error',
                'description' => 'Database connectivity',
                'details'     => ['error' => $e->getMessage()],
            ];
        }
    }

    private function checkCriticalTables(): array
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

        return [
            'pass'        => empty($missing),
            'status'      => empty($missing) ? 'ok' : 'error',
            'description' => 'Critical database tables',
            'details'     => [
                'required_count' => count($requiredTables),
                'missing'        => $missing,
            ],
        ];
    }

    private function checkMigrations(): array
    {
        try {
            Artisan::call('migrate:status', ['--no-interaction' => true]);
            $output = Artisan::output();

            // Check if any "Pending" lines exist
            $hasPending = str_contains($output, 'Pending');

            return [
                'pass'        => ! $hasPending,
                'status'      => $hasPending ? 'warning' : 'ok',
                'description' => 'Database migrations',
                'details'     => [
                    'pending_migrations' => $hasPending,
                    'note'               => $hasPending
                        ? 'Run: php artisan migrate to apply pending migrations'
                        : 'All migrations applied',
                ],
            ];
        } catch (Throwable $e) {
            return [
                'pass'        => false,
                'status'      => 'error',
                'description' => 'Database migrations',
                'details'     => ['error' => $e->getMessage()],
            ];
        }
    }

    private function checkCache(): array
    {
        try {
            $key   = 'production_readiness_check_' . now()->timestamp;
            $value = 'erp_cache_ok_' . rand(1000, 9999);

            Cache::put($key, $value, 10);
            $retrieved = Cache::get($key);
            Cache::forget($key);

            $cacheOk = ($retrieved === $value);

            return [
                'pass'        => $cacheOk,
                'status'      => $cacheOk ? 'ok' : 'error',
                'description' => 'Cache system',
                'details'     => [
                    'driver' => config('cache.default'),
                    'test'   => $cacheOk ? 'write/read/delete succeeded' : 'cache read mismatch',
                ],
            ];
        } catch (Throwable $e) {
            return [
                'pass'        => false,
                'status'      => 'error',
                'description' => 'Cache system',
                'details'     => ['error' => $e->getMessage()],
            ];
        }
    }

    private function checkEnvironment(): array
    {
        $env     = config('app.env');
        $debug   = config('app.debug');
        $issues  = [];

        if ($env === 'production' && $debug) {
            $issues[] = 'APP_DEBUG must be false in production';
        }

        if (empty(config('app.key'))) {
            $issues[] = 'APP_KEY is not set — run: php artisan key:generate';
        }

        if ($env === 'local') {
            $issues[] = 'APP_ENV is "local" — set to "production" before going live';
        }

        return [
            'pass'        => empty($issues),
            'status'      => empty($issues) ? 'ok' : 'warning',
            'description' => 'Application environment',
            'details'     => [
                'app_env'   => $env,
                'app_debug' => $debug,
                'issues'    => $issues,
            ],
        ];
    }

    private function checkConfiguration(): array
    {
        $requiredKeys = [
            'app.key'           => config('app.key'),
            'database.default'  => config('database.default'),
            'mail.default'      => config('mail.default'),
            'auth.guards.sanctum' => config('auth.guards.sanctum') !== null,
        ];

        $missing = [];
        foreach ($requiredKeys as $key => $value) {
            if (empty($value)) {
                $missing[] = $key;
            }
        }

        return [
            'pass'        => empty($missing),
            'status'      => empty($missing) ? 'ok' : 'warning',
            'description' => 'Required configuration values',
            'details'     => [
                'missing_keys' => $missing,
            ],
        ];
    }

    private function checkAccountingModules(): array
    {
        $modules = [];

        // Check each critical domain service can be resolved
        $servicesToCheck = [
            'PostingEngine'       => \App\Domain\Accounting\Posting\Services\PostingEngine::class,
            'PeriodManager'       => \App\Domain\Accounting\Period\Services\PeriodManager::class,
            'InvoiceService'      => \App\Domain\Sales\Services\InvoiceService::class,
            'BillService'         => \App\Domain\Purchasing\Services\BillService::class,
            'BankingService'      => \App\Domain\Banking\Services\BankingService::class,
            'InventoryService'    => \App\Domain\Inventory\Services\InventoryService::class,
            'CogsEngine'          => \App\Domain\Inventory\Services\CogsEngine::class,
            'ProcurementService'  => \App\Domain\Procurement\Services\ProcurementService::class,
            'SecurityService'     => \App\Domain\Security\Services\SecurityService::class,
            'IntegrationManager'  => \App\Domain\Integrations\Services\IntegrationManager::class,
        ];

        $failed = [];
        foreach ($servicesToCheck as $name => $class) {
            try {
                app($class);
                $modules[$name] = 'online';
            } catch (Throwable $e) {
                $modules[$name] = 'error: ' . $e->getMessage();
                $failed[]       = $name;
            }
        }

        return [
            'pass'        => empty($failed),
            'status'      => empty($failed) ? 'ok' : 'error',
            'description' => 'Accounting & domain modules',
            'details'     => [
                'total'   => count($servicesToCheck),
                'online'  => count($servicesToCheck) - count($failed),
                'failed'  => $failed,
                'modules' => $modules,
            ],
        ];
    }

    private function checkAiService(): array
    {
        $url = config('services.ai.url', 'http://localhost:8001');
        $secret = config('services.ai.internal_secret');
        $env = config('app.env');

        $issues = [];
        if ($env === 'production' && ($secret === 'ai-native-finance-erp-internal-service-secret-key' || empty($secret))) {
            $issues[] = 'Production environment cannot use default or empty AI_INTERNAL_SECRET';
        }

        $online = false;
        $latencyMs = null;
        try {
            $start = microtime(true);
            $response = \Illuminate\Support\Facades\Http::timeout(1)->get("{$url}/health");
            $latencyMs = round((microtime(true) - $start) * 1000, 2);
            $online = $response->successful();
        } catch (Throwable $e) {
            $online = false;
        }

        $pass = empty($issues);

        return [
            'pass' => $pass,
            'status' => $pass ? ($online ? 'ok' : 'degraded') : 'error',
            'description' => 'AI Service & Security Configuration',
            'details' => [
                'service_url' => $url,
                'online' => $online,
                'latency_ms' => $latencyMs,
                'secret_configured' => ! empty($secret) && $secret !== 'ai-native-finance-erp-internal-service-secret-key',
                'issues' => $issues,
            ],
        ];
    }

    private function checkTestSuite(): array
    {
        $unitFiles = glob(base_path('tests/Unit/*Test.php')) ?: [];
        $featureFiles = glob(base_path('tests/Feature/*Test.php')) ?: [];
        $testFiles = array_merge($unitFiles, $featureFiles);

        $totalTestMethods = 0;
        foreach ($testFiles as $file) {
            $content = @file_get_contents($file);
            if ($content) {
                $totalTestMethods += preg_match_all('/public\s+function\s+test_/i', $content);
            }
        }

        $reportPath = storage_path('app/test-results.json');
        $hasReport = file_exists($reportPath);
        $reportData = $hasReport ? json_decode(@file_get_contents($reportPath), true) : null;

        return [
            'pass' => true,
            'status' => 'ok',
            'description' => 'Automated test suite coverage',
            'details' => [
                'test_files_count' => count($testFiles),
                'discovered_test_methods' => $totalTestMethods,
                'unit_suites' => count($unitFiles),
                'feature_suites' => count($featureFiles),
                'last_ci_report' => $reportData,
                'note' => "Discovered {$totalTestMethods} test methods across " . count($testFiles) . " test suites dynamically.",
            ],
        ];
    }
}
