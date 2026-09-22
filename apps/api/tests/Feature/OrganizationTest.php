<?php

namespace Tests\Feature;

use App\Domain\Organization\Models\Entity;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Scopes\TenantScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_create_organization_with_primary_entity_and_branch(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test_token')->plainTextToken;

        $payload = [
            'name' => 'Indus Technologies',
            'legal_name' => 'Indus Technologies (Pvt) Ltd',
            'ntn' => '7849201-4',
            'strn' => '3277876123456',
            'country_code' => 'PK',
            'base_currency' => 'PKR',
            'fiscal_year_start_month' => 7,
            'primary_entity_name' => 'Indus Tech HQ',
            'primary_branch_name' => 'Karachi Main',
            'city' => 'Karachi',
        ];

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/organizations', $payload);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'data' => [
                    'organization' => [
                        'id',
                        'name',
                        'legal_name',
                        'ntn',
                        'strn',
                        'base_currency',
                        'fiscal_year_start_month',
                    ],
                ],
                'errors',
            ]);

        $orgId = $response->json('data.organization.id');

        // Verify Database Persistence
        $this->assertDatabaseHas('organizations', [
            'id' => $orgId,
            'ntn' => '7849201-4',
            'base_currency' => 'PKR',
        ]);

        // Verify User Membership as Owner
        $this->assertDatabaseHas('organization_user', [
            'organization_id' => $orgId,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);

        // Verify Default Entity & Branch Creation
        $this->assertDatabaseHas('entities', [
            'organization_id' => $orgId,
            'name' => 'Indus Tech HQ',
            'is_primary' => true,
        ]);

        $this->assertDatabaseHas('branches', [
            'organization_id' => $orgId,
            'name' => 'Karachi Main',
            'city' => 'Karachi',
        ]);
    }

    public function test_user_can_list_their_organizations(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test_token')->plainTextToken;

        $org1 = Organization::create([
            'name' => 'Company A',
            'legal_name' => 'Company A (Pvt) Ltd',
        ]);
        $org1->users()->attach($user->id, ['role' => 'owner', 'is_default' => true]);

        // Org 2 belongs to another user
        $otherUser = User::factory()->create();
        $org2 = Organization::create([
            'name' => 'Company B',
            'legal_name' => 'Company B (Pvt) Ltd',
        ]);
        $org2->users()->attach($otherUser->id, ['role' => 'owner']);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/organizations');

        $response->assertStatus(200);
        $data = $response->json('data.organizations');

        $this->assertCount(1, $data);
        $this->assertEquals('Company A', $data[0]['name']);
    }

    public function test_tenant_isolation_prevents_unauthorized_access(): void
    {
        $userA = User::factory()->create();
        $tokenA = $userA->createToken('test_token_a')->plainTextToken;

        $userB = User::factory()->create();

        $orgB = Organization::create([
            'name' => 'Private Company B',
            'legal_name' => 'Private Company B (Pvt) Ltd',
        ]);
        $orgB->users()->attach($userB->id, ['role' => 'owner']);

        // User A attempts to inspect User B's organization
        $response = $this->withHeader('Authorization', 'Bearer '.$tokenA)
            ->getJson('/api/v1/organizations/'.$orgB->id);

        $response->assertStatus(404)
            ->assertJson([
                'data' => null,
                'errors' => ['Organization not found or access denied.'],
            ]);
    }

    public function test_global_tenant_scope_filters_queries_by_organization(): void
    {
        $org1 = Organization::create(['name' => 'Tenant 1', 'legal_name' => 'Tenant 1 Ltd']);
        $org2 = Organization::create(['name' => 'Tenant 2', 'legal_name' => 'Tenant 2 Ltd']);

        Entity::create(['organization_id' => $org1->id, 'name' => 'Entity 1', 'code' => 'E1']);
        Entity::create(['organization_id' => $org2->id, 'name' => 'Entity 2', 'code' => 'E2']);

        // Set active tenant scope to Tenant 1
        TenantScope::setOrganizationId($org1->id);

        $entities = Entity::all();
        $this->assertCount(1, $entities);
        $this->assertEquals('Entity 1', $entities->first()->name);

        // Reset scope
        TenantScope::setOrganizationId(null);
    }
}
