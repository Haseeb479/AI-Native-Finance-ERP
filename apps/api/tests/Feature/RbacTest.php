<?php

namespace Tests\Feature;

use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RbacTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Seed roles and permissions matrix
        $this->seed(RoleAndPermissionSeeder::class);
    }

    public function test_owner_has_full_permissions_and_can_add_members(): void
    {
        $owner = User::factory()->create(['email' => 'owner@finance.pk']);
        $token = $owner->createToken('owner_token')->plainTextToken;

        $org = Organization::create([
            'name' => 'Apex Traders',
            'legal_name' => 'Apex Traders (Pvt) Ltd',
        ]);
        $org->users()->attach($owner->id, ['role' => 'owner', 'is_default' => true]);

        // Register candidate accountant
        $accountant = User::factory()->create(['email' => 'accountant@finance.pk']);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/organizations/{$org->id}/members", [
                'email' => 'accountant@finance.pk',
                'role' => 'accountant',
            ]);

        $response->assertStatus(201)
            ->assertJson([
                'data' => [
                    'member' => [
                        'email' => 'accountant@finance.pk',
                        'role' => 'accountant',
                    ],
                ],
            ]);

        $this->assertTrue($accountant->hasPermissionInOrganization('accounting.journal.create', $org));
        $this->assertTrue($accountant->hasPermissionInOrganization('accounting.journal.post', $org));
        $this->assertFalse($accountant->hasPermissionInOrganization('users.manage', $org));
    }

    public function test_accountant_cannot_invite_members(): void
    {
        $org = Organization::create([
            'name' => 'Beta Logistics',
            'legal_name' => 'Beta Logistics (Pvt) Ltd',
        ]);

        $accountant = User::factory()->create();
        $org->users()->attach($accountant->id, ['role' => 'accountant']);
        $token = $accountant->createToken('acc_token')->plainTextToken;

        $newMember = User::factory()->create(['email' => 'new@finance.pk']);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/organizations/{$org->id}/members", [
                'email' => 'new@finance.pk',
                'role' => 'staff',
            ]);

        $response->assertStatus(403)
            ->assertJson([
                'data' => null,
                'errors' => ['Unauthorized. Permission users.manage required.'],
            ]);
    }

    public function test_auditor_has_read_only_permissions(): void
    {
        $org = Organization::create([
            'name' => 'Gamma Manufacturing',
            'legal_name' => 'Gamma Manufacturing Ltd',
        ]);

        $auditor = User::factory()->create();
        $org->users()->attach($auditor->id, ['role' => 'auditor']);

        $this->assertTrue($auditor->hasPermissionInOrganization('accounting.view', $org));
        $this->assertTrue($auditor->hasPermissionInOrganization('reports.view', $org));
        $this->assertTrue($auditor->hasPermissionInOrganization('reports.export', $org));
        
        // Cannot post journals or edit invoices
        $this->assertFalse($auditor->hasPermissionInOrganization('accounting.journal.post', $org));
        $this->assertFalse($auditor->hasPermissionInOrganization('sales.invoice.post', $org));
        $this->assertFalse($auditor->hasPermissionInOrganization('users.manage', $org));
    }

    public function test_cannot_remove_the_sole_owner(): void
    {
        $org = Organization::create([
            'name' => 'Solo Founder Org',
            'legal_name' => 'Solo Founder Org (Pvt) Ltd',
        ]);

        $owner = User::factory()->create();
        $org->users()->attach($owner->id, ['role' => 'owner']);
        $token = $owner->createToken('owner_token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson("/api/v1/organizations/{$org->id}/members/{$owner->id}");

        $response->assertStatus(422)
            ->assertJson([
                'data' => null,
                'errors' => ['Cannot remove the primary owner. Transfer ownership first.'],
            ]);
    }
}
