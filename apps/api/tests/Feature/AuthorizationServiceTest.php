<?php

namespace Tests\Feature;

use App\Domain\Organization\Models\Organization;
use App\Domain\Security\Services\AuthorizationService;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthorizationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_has_wildcard_authority(): void
    {
        $org = Organization::create([
            'name' => 'Owner Org Corp',
            'legal_name' => 'Owner Org Corp (Pvt) Ltd',
            'base_currency' => 'PKR',
            'fiscal_year_start_month' => 1,
        ]);
        $owner = User::factory()->create();
        $owner->organizations()->attach($org->id, ['role' => 'owner']);

        $service = app(AuthorizationService::class);

        $this->assertTrue($service->can($owner, 'journals.post', $org));
        $this->assertTrue($service->can($owner, 'invoices.pay', $org));
        $this->assertTrue($service->can($owner, 'any.custom.capability', $org));
    }

    public function test_role_based_capability_boundaries(): void
    {
        $org = Organization::create([
            'name' => 'Staff Org Corp',
            'legal_name' => 'Staff Org Corp (Pvt) Ltd',
            'base_currency' => 'PKR',
            'fiscal_year_start_month' => 1,
        ]);
        $accountant = User::factory()->create();
        $accountant->organizations()->attach($org->id, ['role' => 'accountant']);

        $staff = User::factory()->create();
        $staff->organizations()->attach($org->id, ['role' => 'staff']);

        $service = app(AuthorizationService::class);


        // Accountant can post journals
        $this->assertTrue($service->can($accountant, 'journals.post', $org));

        // Staff can create invoices but cannot post journals or pay invoices
        $this->assertTrue($service->can($staff, 'invoices.create', $org));
        $this->assertFalse($service->can($staff, 'journals.post', $org));
        $this->assertFalse($service->can($staff, 'invoices.pay', $org));
    }

    public function test_comprehensive_permission_matrix_across_all_roles(): void
    {
        $org = Organization::create([
            'name' => 'Matrix Org',
            'legal_name' => 'Matrix Org (Pvt) Ltd',
            'base_currency' => 'PKR',
            'fiscal_year_start_month' => 1,
        ]);

        $roles = ['owner', 'admin', 'accountant', 'finance_manager', 'staff', 'auditor'];
        $users = [];
        foreach ($roles as $role) {
            $user = User::factory()->create();
            $user->organizations()->attach($org->id, ['role' => $role]);
            $users[$role] = $user;
        }

        $service = app(AuthorizationService::class);

        // 1. Owner: wildcard all
        $this->assertTrue($service->can($users['owner'], 'journals.post', $org));
        $this->assertTrue($service->can($users['owner'], 'audit.view', $org));
        $this->assertTrue($service->can($users['owner'], 'members.manage', $org));

        // 2. Admin: management and audit
        $this->assertTrue($service->can($users['admin'], 'journals.post', $org));
        $this->assertTrue($service->can($users['admin'], 'audit.view', $org));
        $this->assertTrue($service->can($users['admin'], 'members.manage', $org));

        // 3. Accountant: journals, invoices, banking, but NOT members.manage
        $this->assertTrue($service->can($users['accountant'], 'journals.post', $org));
        $this->assertTrue($service->can($users['accountant'], 'invoices.pay', $org));
        $this->assertFalse($service->can($users['accountant'], 'members.manage', $org));

        // 4. Finance Manager: journals, invoices, banking, but NOT members.manage or journals.reverse
        $this->assertTrue($service->can($users['finance_manager'], 'journals.post', $org));
        $this->assertTrue($service->can($users['finance_manager'], 'invoices.pay', $org));
        $this->assertFalse($service->can($users['finance_manager'], 'members.manage', $org));

        // 5. Staff: invoices.create only; cannot post or pay
        $this->assertTrue($service->can($users['staff'], 'invoices.create', $org));
        $this->assertFalse($service->can($users['staff'], 'journals.post', $org));
        $this->assertFalse($service->can($users['staff'], 'invoices.pay', $org));
        $this->assertFalse($service->can($users['staff'], 'audit.view', $org));

        // 6. Auditor: read-only; can view journals, reports, audit logs, but CANNOT mutate/post
        $this->assertTrue($service->can($users['auditor'], 'journals.view', $org));
        $this->assertTrue($service->can($users['auditor'], 'audit.view', $org));
        $this->assertTrue($service->can($users['auditor'], 'reports.export', $org));
        $this->assertFalse($service->can($users['auditor'], 'journals.post', $org));
        $this->assertFalse($service->can($users['auditor'], 'invoices.pay', $org));
        $this->assertFalse($service->can($users['auditor'], 'invoices.create', $org));
    }

    public function test_separation_of_duties_prevents_maker_from_approving_or_posting(): void
    {
        $user = User::factory()->create();
        $service = app(AuthorizationService::class);

        // Mock a journal created by the user
        $ownRecord = (object) ['id' => 'jr-1', 'created_by' => $user->id];

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('Separation of Duties (SoD) Violation');

        $service->assertSeparationOfDuties($user, $ownRecord, 'post');
    }

    public function test_separation_of_duties_allows_different_checker_to_post(): void
    {
        $maker = User::factory()->create();
        $checker = User::factory()->create();
        $service = app(AuthorizationService::class);

        $record = (object) ['id' => 'jr-2', 'created_by' => $maker->id];

        // Should not throw any exception
        $service->assertSeparationOfDuties($checker, $record, 'post');
        $this->assertTrue(true);
    }
}
