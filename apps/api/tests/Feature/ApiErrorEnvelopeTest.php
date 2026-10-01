<?php

namespace Tests\Feature;

use Tests\TestCase;

class ApiErrorEnvelopeTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    /**
     * Test that non-existent API routes return standard error envelope with correlation ID (P2-03).
     */
    public function test_not_found_returns_standard_envelope_with_correlation_id(): void
    {
        $response = $this->withHeader('X-Correlation-ID', 'corr-error-test-404')
            ->getJson('/api/v1/non-existent-endpoint');

        $response->assertStatus(404)
            ->assertHeader('X-Correlation-ID', 'corr-error-test-404')
            ->assertJson([
                'data' => null,
                'meta' => [
                    'correlation_id' => 'corr-error-test-404',
                ],
                'errors' => [
                    [
                        'code' => 'NOT_FOUND',
                    ],
                ],
            ])
            ->assertJsonStructure([
                'data',
                'meta' => ['correlation_id', 'timestamp'],
                'errors' => [
                    ['code', 'message'],
                ],
            ]);
    }

    /**
     * Test authentication failure returns standard error envelope (P2-03).
     */
    public function test_unauthenticated_returns_standard_envelope(): void
    {
        $response = $this->withHeader('X-Correlation-ID', 'corr-error-test-401')
            ->getJson('/api/v1/organizations');

        $response->assertStatus(401)
            ->assertHeader('X-Correlation-ID', 'corr-error-test-401')
            ->assertJson([
                'data' => null,
                'meta' => [
                    'correlation_id' => 'corr-error-test-401',
                ],
                'errors' => [
                    [
                        'code' => 'UNAUTHENTICATED',
                    ],
                ],
            ]);
    }

    /**
     * Test controller validation failure returns standard error envelope (P2-03).
     */
    public function test_controller_validation_failure_returns_standard_envelope(): void
    {
        $user = \App\Models\User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/auth/mfa/confirm', []);

        $response->assertStatus(422)
            ->assertJsonStructure([
                'data',
                'meta' => ['correlation_id', 'timestamp'],
                'errors' => [
                    [
                        'code',
                        'message',
                        'details',
                    ],
                ],
            ]);

        $this->assertEquals('VALIDATION_ERROR', $response->json('errors.0.code'));
    }

}
