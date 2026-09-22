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
}
