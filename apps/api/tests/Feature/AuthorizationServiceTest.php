<?php

namespace Tests\Feature;

use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\UserOrganizationScope;
use App\Domain\Identity\Services\AuthorizationService;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Entity;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class AuthorizationServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
    }

    public function test_owner_has_wildcard_permission_and_full_hierarchy_scope(): void
    {
        $org = Organization::create(['name' => 'Owner Corp', 'legal_name' => 'Owner Corp Ltd', 'country_code' => 'PK', 'base_currency' => 'PKR']);
        $entity = Entity::create(['organization_id' => $org->id, 'name' => 'Main Entity', 'code' => 'MAIN', 'currency' => 'PKR']);
        $branch = Branch::create(['organization_id' => $org->id, 'entity_id' => $entity->id, 'name' => 'Main Branch', 'code' => 'BR1']);

        $owner = User::factory()->create();
        $owner->organizations()->attach($org->id, ['role' => 'owner', 'is_default' => true]);

        $authService = app(AuthorizationService::class);

        // Owner can perform any valid permission
        $this->assertTrue($authService->can($owner, 'accounting.journal.post', $org, $entity, $branch));
        $this->assertTrue($authService->can($owner, 'users.manage', $org));
        $this->assertTrue($authService->canAccessEntity($owner, $org, $entity));
        $this->assertTrue($authService->canAccessBranch($owner, $org, $branch));

        // Laravel Gate integration
        $this->assertTrue(Gate::forUser($owner)->allows('accounting.journal.post', [$org, $entity]));
    }

    public function test_role_permissions_are_strictly_enforced(): void
    {
        $org = Organization::create(['name' => 'Staff Corp', 'legal_name' => 'Staff Corp Ltd', 'country_code' => 'PK', 'base_currency' => 'PKR']);
        $entity = Entity::create(['organization_id' => $org->id, 'name' => 'Staff Entity', 'code' => 'STF1', 'currency' => 'PKR']);

        $staffUser = User::factory()->create();
        $staffUser->organizations()->attach($org->id, ['role' => 'staff']);

        $authService = app(AuthorizationService::class);

        // Staff can create bill
        $this->assertTrue($authService->can($staffUser, 'purchases.bill.create', $org, $entity));

        // Staff CANNOT post journal entries or manage users
        $this->assertFalse($authService->can($staffUser, 'accounting.journal.post', $org, $entity));
        $this->assertFalse($authService->can($staffUser, 'users.manage', $org));

        // Gate also denies
        $this->assertFalse(Gate::forUser($staffUser)->allows('accounting.journal.post', [$org, $entity]));
    }

    public function test_cross_tenant_entity_access_is_rejected(): void
    {
        $orgA = Organization::create(['name' => 'Org A', 'legal_name' => 'Org A Ltd', 'country_code' => 'PK', 'base_currency' => 'PKR']);
        $orgB = Organization::create(['name' => 'Org B', 'legal_name' => 'Org B Ltd', 'country_code' => 'PK', 'base_currency' => 'PKR']);

        $entityB = Entity::create(['organization_id' => $orgB->id, 'name' => 'Entity B', 'code' => 'ENTB', 'currency' => 'PKR']);

        $ownerA = User::factory()->create();
        $ownerA->organizations()->attach($orgA->id, ['role' => 'owner']);

        $authService = app(AuthorizationService::class);

        // Attempting to access Org B's entity with Org A context must fail
        $this->assertFalse($authService->can($ownerA, 'accounting.view', $orgA, $entityB));
        $this->assertFalse($authService->canAccessEntity($ownerA, $orgA, $entityB));
    }

    public function test_sub_entity_and_branch_scoping_enforcement(): void
    {
        $org = Organization::create(['name' => 'Scope Corp', 'legal_name' => 'Scope Corp Ltd', 'country_code' => 'PK', 'base_currency' => 'PKR']);
        $entityLahore = Entity::create(['organization_id' => $org->id, 'name' => 'Lahore Entity', 'code' => 'LHR', 'currency' => 'PKR']);
        $entityKarachi = Entity::create(['organization_id' => $org->id, 'name' => 'Karachi Entity', 'code' => 'KHI', 'currency' => 'PKR']);

        $branchLahoreMain = Branch::create([
            'organization_id' => $org->id,
            'entity_id' => $entityLahore->id,
            'name' => 'Lahore Main',
            'code' => 'LHR-01',
        ]);
        $branchLahoreMall = Branch::create([
            'organization_id' => $org->id,
            'entity_id' => $entityLahore->id,
            'name' => 'Lahore Mall',
            'code' => 'LHR-02',
        ]);

        $accountant = User::factory()->create();
        $accountant->organizations()->attach($org->id, ['role' => 'accountant']);

        // Explicitly scope user ONLY to Lahore Main branch
        UserOrganizationScope::create([
            'user_id' => $accountant->id,
            'organization_id' => $org->id,
            'entity_id' => $entityLahore->id,
            'branch_id' => $branchLahoreMain->id,
        ]);

        $authService = app(AuthorizationService::class);

        // Can access Lahore Main branch
        $this->assertTrue($authService->can($accountant, 'accounting.journal.create', $org, $entityLahore, $branchLahoreMain));
        $this->assertTrue($authService->canAccessBranch($accountant, $org, $branchLahoreMain));

        // CANNOT access Lahore Mall branch (different branch in same entity)
        $this->assertFalse($authService->can($accountant, 'accounting.journal.create', $org, $entityLahore, $branchLahoreMall));

        // CANNOT access Karachi entity
        $this->assertFalse($authService->can($accountant, 'accounting.journal.create', $org, $entityKarachi));
        $this->assertFalse($authService->canAccessEntity($accountant, $org, $entityKarachi));
    }

    public function test_maker_checker_separation_of_duties_prevents_self_approval(): void
    {
        $maker = User::factory()->create();
        $approver = User::factory()->create();

        $authService = app(AuthorizationService::class);

        // Different users pass maker-checker assertion
        $authService->assertMakerCheckerSeparation($maker, $approver, 'post');

        // Same user as maker and approver throws AuthorizationException
        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('Separation of Duties violation');

        $authService->assertMakerCheckerSeparation($maker, $maker, 'post');
    }
}
