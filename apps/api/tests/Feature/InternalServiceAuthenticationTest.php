<?php

namespace Tests\Feature;

use App\Domain\AI\Services\AiGatewayService;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InternalServiceAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.ai.internal_secret' => 'internal-service-test-secret']);
    }

    public function test_internal_rpc_requires_a_valid_service_token(): void
    {
        $response = $this->getJson('/api/v1/internal/organizations/00000000-0000-0000-0000-000000000001/transactions/search');

        $response->assertUnauthorized();
    }

    public function test_internal_rpc_rejects_a_user_outside_the_token_organization(): void
    {
        $user = User::factory()->create();
        $organization = Organization::create([
            'name' => 'Scoped Organization',
            'legal_name' => 'Scoped Organization Ltd',
        ]);
        $token = app(AiGatewayService::class)->generateInternalServiceToken($organization, $user);

        $response = $this->withToken($token)
            ->getJson("/api/v1/internal/organizations/{$organization->id}/transactions/search");

        $response->assertForbidden()
            ->assertJsonPath('errors.0.code', 'FORBIDDEN');
    }

    public function test_internal_rpc_rejects_tokens_scoped_to_another_organization(): void
    {
        $user = User::factory()->create();
        $tokenOrganization = Organization::create([
            'name' => 'Token Organization',
            'legal_name' => 'Token Organization Ltd',
        ]);
        $routeOrganization = Organization::create([
            'name' => 'Route Organization',
            'legal_name' => 'Route Organization Ltd',
        ]);
        $tokenOrganization->users()->attach($user->id, ['role' => 'owner']);
        $token = app(AiGatewayService::class)->generateInternalServiceToken($tokenOrganization, $user);

        $response = $this->withToken($token)
            ->getJson("/api/v1/internal/organizations/{$routeOrganization->id}/transactions/search");

        $response->assertForbidden()
            ->assertJsonPath('errors.0.code', 'SCOPE_MISMATCH');
    }
}
