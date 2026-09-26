<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    /**
     * Test the API health check endpoint returns 200 and healthy database status.
     */
    public function test_api_health_check_returns_healthy(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'status' => 'healthy',
                    'services' => [
                        'database' => [
                            'status' => 'ok',
                            'driver' => config('database.default'),
                        ],
                    ],
                    'version' => 'v1.0.0',
                ],
                'errors' => [],
            ])
            ->assertJsonStructure([
                'data' => [
                    'status',
                    'services' => [
                        'database' => [
                            'status',
                            'driver',
                        ],
                    ],
                    'version',
                ],
                'meta' => [
                    'timestamp',
                    'environment',
                ],
                'errors',
            ]);
    }

    /**
     * Test the production readiness endpoint returns dynamic checks for database, AI, and test suite.
     */
    public function test_production_readiness_returns_dynamic_metrics(): void
    {
        $response = $this->getJson('/api/v1/health/production-readiness');

        $response->assertJsonStructure([
            'data' => [
                'status',
                'ready',
                'total_checks',
                'passing_checks',
                'failing_checks',
                'checks' => [
                    'database',
                    'critical_tables',
                    'migrations',
                    'cache',
                    'environment',
                    'configuration',
                    'accounting_modules',
                    'ai_service',
                    'test_suite' => [
                        'pass',
                        'status',
                        'description',
                        'details' => [
                            'test_files_count',
                            'discovered_test_methods',
                        ],
                    ],
                ],
            ],
        ]);
    }
}
