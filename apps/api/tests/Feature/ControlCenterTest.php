<?php

namespace Tests\Feature;

use App\Domain\Organization\Models\Organization;
use App\Domain\ControlCenter\Models\StaffAuditEvent;
use App\Domain\ControlCenter\Models\StaffInvitation;
use App\Domain\ControlCenter\Models\StaffMember;
use App\Notifications\ControlCenterStaffInvitationNotification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ControlCenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_owner_without_control_center_provisioning_is_denied_and_audited(): void
    {
        config(['control_center.staff_emails' => []]);
        $customer = User::factory()->create(['email' => 'customer@example.test']);
        $organization = $this->organizationFor($customer);

        Sanctum::actingAs($customer);

        $this->getJson('/api/v1/internal/control-center/organizations')->assertForbidden();

        $this->assertDatabaseHas('staff_audit_events', [
            'actor_user_id' => $customer->id,
            'action' => 'control_center.organizations.index',
            'outcome' => 'denied',
        ]);
        $this->assertDatabaseMissing('staff_audit_events', [
            'organization_id' => $organization->id,
        ]);
    }

    public function test_unconfigured_staff_allowlist_fails_closed(): void
    {
        config(['control_center.staff_emails' => []]);
        $user = User::factory()->create(['email' => 'staff@example.test']);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/internal/control-center/organizations')->assertForbidden();
    }

    public function test_staff_without_mfa_can_only_complete_preflight_and_cannot_access_operations(): void
    {
        $staff = User::factory()->create(['email' => 'setup@example.test']);
        config(['control_center.staff_emails' => ['setup@example.test']]);
        Sanctum::actingAs($staff);

        $this->getJson('/api/v1/internal/control-center/staff-preflight')
            ->assertOk()
            ->assertJsonPath('data.mfa_enabled', false);
        $this->getJson('/api/v1/internal/control-center/organizations')->assertForbidden();

        $this->assertDatabaseHas('staff_audit_events', [
            'actor_user_id' => $staff->id,
            'action' => 'control_center.organizations.index',
            'outcome' => 'denied',
        ]);
    }

    public function test_staff_audit_events_reject_model_updates_and_deletes(): void
    {
        $event = StaffAuditEvent::query()->create([
            'action' => 'control_center.organizations.index',
            'outcome' => 'allowed',
            'created_at' => now(),
        ]);

        try {
            $event->update(['outcome' => 'denied']);
            $this->fail('Audit events must not be mutable.');
        } catch (\LogicException $exception) {
            $this->assertSame('Staff audit events are append-only.', $exception->getMessage());
        }
        try {
            DB::table('staff_audit_events')->where('id', $event->id)->update(['outcome' => 'denied']);
            $this->fail('Database-level audit event updates must be rejected.');
        } catch (\Illuminate\Database\QueryException) {
            $this->assertDatabaseHas('staff_audit_events', ['id' => $event->id, 'outcome' => 'allowed']);
        }

        try {
            $event->delete();
            $this->fail('Audit events must not be deletable.');
        } catch (\LogicException $exception) {
            $this->assertSame('Staff audit events are append-only.', $exception->getMessage());
        }
        try {
            DB::table('staff_audit_events')->where('id', $event->id)->delete();
            $this->fail('Database-level audit event deletes must be rejected.');
        } catch (\Illuminate\Database\QueryException) {
            $this->assertDatabaseHas('staff_audit_events', ['id' => $event->id, 'outcome' => 'allowed']);
        }
    }

    public function test_provisioned_staff_can_read_safe_directory_and_detail_with_audit_records(): void
    {
        $staff = $this->staffFor('staff@example.test');
        config(['control_center.staff_emails' => ['staff@example.test']]);
        $owner = User::factory()->create(['name' => 'Company Owner', 'email' => 'owner@example.test']);
        $organization = $this->organizationFor($owner);
        $planId = 'control-center-test-plan';

        DB::table('plans')->insert([
            'id' => $planId,
            'name' => 'Test Plan',
            'price_monthly' => 123456.78,
            'currency' => 'PKR',
            'max_seats' => 10,
            'monthly_transaction_limit' => 500,
            'monthly_ai_query_limit' => 200,
            'max_storage_mb' => 1024,
            'features' => json_encode(['feature']),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('subscriptions')->insert([
            'id' => (string) Str::uuid(),
            'organization_id' => $organization->id,
            'plan_id' => $planId,
            'status' => 'active',
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('usage_meters')->insert([
            'organization_id' => $organization->id,
            'metric_name' => 'ai_queries',
            'billing_period' => now()->format('Y-m'),
            'usage_count' => 12,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('usage_meters')->insert([
            'organization_id' => $organization->id,
            'metric_name' => 'sensitive_internal_metric',
            'billing_period' => now()->format('Y-m'),
            'usage_count' => 9000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Sanctum::actingAs($staff);

        $listResponse = $this->getJson('/api/v1/internal/control-center/organizations')->assertOk();
        $summary = $listResponse->json('data.organizations.0');
        $this->assertSame([
            'id', 'name', 'status', 'created_at', 'owner', 'members_count',
            'onboarding', 'subscription', 'usage',
        ], array_keys($summary));
        $this->assertSame('Company Owner', $summary['owner']['name']);
        $this->assertSame(1, $summary['members_count']);
        $this->assertSame(['primary_entity_configured' => false, 'branch_configured' => false], $summary['onboarding']);
        $this->assertSame([
            'status' => 'active',
            'plan_name' => 'Test Plan',
            'current_period_end' => DB::table('subscriptions')
                ->where('organization_id', $organization->id)
                ->value('current_period_end'),
        ], $summary['subscription']);
        $this->assertSame([[
            'metric' => 'ai_queries',
            'count' => 12,
            'period' => now()->format('Y-m'),
        ]], $summary['usage']);
        $this->assertStringNotContainsString('123456.78', $listResponse->getContent());
        $this->assertStringNotContainsString('sensitive_internal_metric', $listResponse->getContent());
        foreach (['invoice', 'bill', 'bank', 'ledger', 'document', 'imperson'] as $prohibitedData) {
            $this->assertStringNotContainsString($prohibitedData, strtolower($listResponse->getContent()));
        }

        $this->getJson('/api/v1/internal/control-center/organizations/'.$organization->id)
            ->assertOk()
            ->assertJsonPath('data.organization.id', $organization->id)
            ->assertJsonMissingPath('data.organization.legal_name')
            ->assertJsonMissingPath('data.organization.price_monthly');

        $this->assertDatabaseHas('staff_audit_events', [
            'actor_user_id' => $staff->id,
            'action' => 'control_center.organizations.index',
            'outcome' => 'allowed',
        ]);
        $this->assertDatabaseHas('staff_audit_events', [
            'actor_user_id' => $staff->id,
            'action' => 'control_center.organizations.show',
            'organization_id' => $organization->id,
            'outcome' => 'allowed',
        ]);
        $this->assertSame(2, DB::table('staff_audit_events')->where('actor_user_id', $staff->id)->count());
    }

    public function test_provisioned_staff_can_read_demo_requests_and_customer_cannot(): void
    {
        $requestId = DB::table('demo_requests')->insertGetId([
            'first_name' => 'Prospective',
            'last_name' => 'Customer',
            'email' => 'prospect@example.test',
            'company_name' => 'Example Ltd',
            'company_size' => '11-50',
            'role' => 'CFO',
            'referral_source' => 'Partner',
            'message' => 'Interested in onboarding.',
            'status' => 'new',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $customer = User::factory()->create(['email' => 'customer@example.test']);
        Sanctum::actingAs($customer);
        $this->getJson('/api/v1/internal/control-center/demo-requests')->assertForbidden();

        $staff = $this->staffFor('staff@example.test');
        config(['control_center.staff_emails' => ['staff@example.test']]);
        config(['control_center.staff_roles' => ['staff@example.test' => 'ops_sales']]);
        Sanctum::actingAs($staff);

        $this->getJson('/api/v1/internal/control-center/demo-requests')
            ->assertOk()
            ->assertJsonPath('data.requests.0.company_name', 'Example Ltd')
            ->assertJsonPath('data.requests.0.email', 'prospect@example.test');

        $this->patchJson('/api/v1/internal/control-center/demo-requests/'.$requestId, ['status' => 'contacted'])
            ->assertOk()
            ->assertJsonPath('data.status', 'contacted');

        $this->assertDatabaseHas('staff_audit_events', [
            'actor_user_id' => $staff->id,
            'action' => 'control_center.demo_requests.index',
            'outcome' => 'allowed',
        ]);
        $this->assertDatabaseHas('staff_audit_events', [
            'actor_user_id' => $staff->id,
            'action' => 'control_center.demo_requests.update',
            'outcome' => 'allowed',
        ]);
        $this->assertDatabaseHas('staff_audit_events', [
            'actor_user_id' => $staff->id,
            'action' => 'control_center.demo_requests.update',
            'metadata->status' => 'contacted',
        ]);
        $this->assertDatabaseHas('staff_audit_events', [
            'actor_user_id' => $staff->id,
            'action' => 'control_center.demo_requests.update',
            'metadata->resource_id' => (string) $requestId,
        ]);
    }

    public function test_read_only_staff_cannot_change_demo_status(): void
    {
        $demoRequestId = DB::table('demo_requests')->insertGetId([
            'first_name' => 'Prospective',
            'last_name' => 'Customer',
            'email' => 'prospect@example.test',
            'company_name' => 'Example Ltd',
            'company_size' => '11-50',
            'status' => 'new',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $staff = $this->staffFor('readonly@example.test');
        config(['control_center.staff_emails' => ['readonly@example.test']]);
        config(['control_center.staff_roles' => ['readonly@example.test' => 'ops_readonly']]);
        Sanctum::actingAs($staff);

        $this->patchJson('/api/v1/internal/control-center/demo-requests/'.$demoRequestId, ['status' => 'closed'])
            ->assertForbidden();

        $this->assertDatabaseHas('staff_audit_events', [
            'actor_user_id' => $staff->id,
            'action' => 'control_center.demo_requests.update',
            'outcome' => 'denied',
        ]);
    }

    public function test_support_staff_can_create_and_update_audited_case(): void
    {
        $staff = $this->staffFor('support@example.test');
        config(['control_center.staff_emails' => ['support@example.test']]);
        config(['control_center.staff_roles' => ['support@example.test' => 'ops_support']]);
        Sanctum::actingAs($staff);

        $response = $this->postJson('/api/v1/internal/control-center/support-cases', [
            'customer_email' => 'customer@example.test',
            'category' => 'access',
            'subject' => 'Unable to sign in',
            'description' => 'Customer cannot access the workspace after MFA enrollment.',
            'priority' => 'high',
        ])->assertCreated()->assertJsonPath('data.case.status', 'open');
        $caseId = $response->json('data.case.id');
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/i', (string) $caseId);

        $this->patchJson('/api/v1/internal/control-center/support-cases/'.$caseId, [
            'status' => 'in_progress',
        ])->assertOk()->assertJsonPath('data.case.status', 'in_progress');

        $this->assertDatabaseHas('staff_audit_events', [
            'actor_user_id' => $staff->id,
            'action' => 'control_center.support_cases.store',
            'outcome' => 'allowed',
        ]);
        $this->assertDatabaseHas('staff_audit_events', [
            'actor_user_id' => $staff->id,
            'action' => 'control_center.support_cases.update',
            'outcome' => 'allowed',
        ]);
    }

    public function test_operations_admin_can_provision_and_revoke_mfa_staff_accounts(): void
    {
        $admin = $this->staffFor('admin@example.test');
        config(['control_center.staff_emails' => ['admin@example.test']]);
        config(['control_center.staff_roles' => ['admin@example.test' => 'ops_admin']]);
        Sanctum::actingAs($admin);
        $newStaff = $this->staffFor('new-support@example.test');

        $this->postJson('/api/v1/internal/control-center/staff', [
            'email' => $newStaff->email,
            'role' => 'ops_support',
        ])->assertCreated()->assertJsonPath('data.staff.role', 'ops_support')
            ->assertJsonPath('data.staff.status', 'active');
        $memberId = DB::table('control_center_staff')->where('user_id', $newStaff->id)->value('id');

        $this->getJson('/api/v1/internal/control-center/staff')
            ->assertOk()
            ->assertJsonPath('data.staff.0.is_bootstrap', true);

        Sanctum::actingAs($newStaff);
        $this->getJson('/api/v1/internal/control-center/session')
            ->assertOk()
            ->assertJsonPath('data.role', 'ops_support')
            ->assertJsonPath('data.mfa_enabled', true);
        $this->getJson('/api/v1/internal/control-center/support-cases')->assertOk();
        $this->getJson('/api/v1/internal/control-center/demo-requests')->assertForbidden();

        Sanctum::actingAs($admin);
        $this->patchJson('/api/v1/internal/control-center/staff/'.$memberId, [
            'role' => 'ops_manager',
        ])->assertOk()->assertJsonPath('data.staff.role', 'ops_manager');

        $this->patchJson('/api/v1/internal/control-center/staff/'.$memberId, [
            'status' => 'revoked',
        ])->assertOk()->assertJsonPath('data.staff.status', 'revoked');

        Sanctum::actingAs($newStaff);
        $this->getJson('/api/v1/internal/control-center/session')->assertForbidden();
        $this->assertDatabaseHas('staff_audit_events', [
            'actor_user_id' => $admin->id,
            'action' => 'control_center.staff.store',
            'outcome' => 'allowed',
        ]);
        $this->assertDatabaseHas('staff_audit_events', [
            'actor_user_id' => $admin->id,
            'action' => 'control_center.staff.update',
            'metadata->status' => 'revoked',
        ]);
    }

    public function test_operations_admin_can_invite_a_new_staff_identity_and_invite_is_single_use(): void
    {
        Notification::fake();
        $admin = $this->staffFor('invite-admin@example.test');
        config([
            'control_center.staff_emails' => ['invite-admin@example.test'],
            'control_center.staff_roles' => ['invite-admin@example.test' => 'ops_admin'],
            'control_center.ops_portal_url' => 'https://ops.example.test',
            'control_center.ops_allowed_hosts' => ['ops.example.test'],
        ]);
        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/internal/control-center/staff-invitations', [
            'email' => 'new-operator@example.test',
            'role' => 'ops_support',
        ])->assertCreated()
            ->assertJsonPath('data.invitation.email', 'new-operator@example.test')
            ->assertJsonPath('data.invitation.role', 'ops_support')
            ->assertJsonMissingPath('data.invitation.token');

        $this->assertDatabaseMissing('users', ['email' => 'new-operator@example.test']);
        $this->assertDatabaseHas('control_center_staff_invitations', [
            'email' => 'new-operator@example.test',
            'role' => 'ops_support',
            'accepted_at' => null,
        ]);

        $token = null;
        Notification::assertSentOnDemand(
            ControlCenterStaffInvitationNotification::class,
            function (ControlCenterStaffInvitationNotification $notification, array $channels, object $notifiable) use (&$token): bool {
                $token = $notification->token;
                $this->assertSame(['mail'], $channels);
                $this->assertSame(
                    'ops.example.test',
                    parse_url($notification->toMail($notifiable)->actionUrl, PHP_URL_HOST),
                );

                return true;
            },
        );
        $this->assertIsString($token);

        $this->postJson('/api/v1/internal/control-center/staff-invitations', [
            'email' => 'new-operator@example.test',
            'role' => 'ops_support',
        ])->assertCreated();
        Notification::assertSentOnDemandTimes(ControlCenterStaffInvitationNotification::class, 2);
        $rotatedToken = null;
        Notification::assertSentOnDemand(
            ControlCenterStaffInvitationNotification::class,
            function (ControlCenterStaffInvitationNotification $notification) use (&$rotatedToken): bool {
                $rotatedToken = $notification->token;

                return true;
            },
        );
        $this->assertNotSame($token, $rotatedToken);

        $this->postJson('/api/v1/internal/control-center/staff-invitations/accept', [
            'email' => 'new-operator@example.test',
            'name' => 'New Operator',
            'token' => $token,
            'password' => 'A-long-unique-staff-password-28',
            'password_confirmation' => 'A-long-unique-staff-password-28',
        ])->assertUnprocessable();

        $this->postJson('/api/v1/internal/control-center/staff-invitations/accept', [
            'email' => 'new-operator@example.test',
            'name' => 'New Operator',
            'token' => $rotatedToken,
            'password' => 'A-long-unique-staff-password-28',
            'password_confirmation' => 'A-long-unique-staff-password-28',
        ])->assertCreated()
            ->assertJsonPath('data.role', 'ops_support')
            ->assertJsonPath('data.status', 'pending_mfa');

        $newStaff = User::query()->where('email', 'new-operator@example.test')->firstOrFail();
        $this->assertNotNull($newStaff->email_verified_at);
        $this->assertTrue(Hash::check('A-long-unique-staff-password-28', $newStaff->password));
        $this->assertDatabaseHas('control_center_staff', [
            'user_id' => $newStaff->id,
            'role' => 'ops_support',
            'status' => 'pending_mfa',
        ]);
        $this->assertDatabaseHas('staff_audit_events', [
            'actor_user_id' => $admin->id,
            'action' => 'control_center.staff_invitation.accept',
            'outcome' => 'allowed',
        ]);

        $this->postJson('/api/v1/internal/control-center/staff-invitations/accept', [
            'email' => 'new-operator@example.test',
            'name' => 'New Operator',
            'token' => $token,
            'password' => 'A-long-unique-staff-password-28',
            'password_confirmation' => 'A-long-unique-staff-password-28',
        ])->assertUnprocessable();
    }

    public function test_staff_invite_requires_a_configured_dedicated_portal_host(): void
    {
        Notification::fake();
        $admin = $this->staffFor('invite-host-admin@example.test');
        config([
            'control_center.staff_emails' => ['invite-host-admin@example.test'],
            'control_center.staff_roles' => ['invite-host-admin@example.test' => 'ops_admin'],
            'control_center.ops_portal_url' => 'http://ops.example.test',
            'control_center.ops_allowed_hosts' => ['ops.example.test'],
            'control_center.require_https' => true,
        ]);
        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/internal/control-center/staff-invitations', [
            'email' => 'not-invited@example.test',
            'role' => 'ops_support',
        ])->assertStatus(503);

        $this->assertDatabaseMissing('control_center_staff_invitations', [
            'email' => 'not-invited@example.test',
        ]);
        Notification::assertNothingSent();
    }

    public function test_localhost_invitation_url_is_allowed_in_production_mode(): void
    {
        Notification::fake();
        $admin = $this->staffFor('localhost-admin@example.test');
        config([
            'control_center.staff_emails' => ['localhost-admin@example.test'],
            'control_center.staff_roles' => ['localhost-admin@example.test' => 'ops_admin'],
            'control_center.ops_portal_url' => 'http://localhost:3000',
            'control_center.ops_allowed_hosts' => ['localhost'],
            'control_center.require_https' => true,
        ]);
        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/internal/control-center/staff-invitations', [
            'email' => 'local-invitee@example.test',
            'role' => 'ops_support',
        ])->assertCreated();

        Notification::assertSentOnDemand(
            ControlCenterStaffInvitationNotification::class,
            function (ControlCenterStaffInvitationNotification $notification, array $channels, object $notifiable): bool {
                $this->assertSame(['mail'], $channels);
                $this->assertSame(
                    'http://localhost:3000',
                    preg_replace('~/ops/invite\\?.*$~', '', $notification->toMail($notifiable)->actionUrl),
                );

                return true;
            },
        );
    }

    public function test_invitation_cannot_be_accepted_after_its_administrator_is_removed(): void
    {
        Notification::fake();
        $admin = $this->staffFor('removed-invite-admin@example.test');
        config([
            'control_center.staff_emails' => ['removed-invite-admin@example.test'],
            'control_center.staff_roles' => ['removed-invite-admin@example.test' => 'ops_admin'],
            'control_center.ops_portal_url' => 'https://ops.example.test',
            'control_center.ops_allowed_hosts' => ['ops.example.test'],
        ]);
        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/internal/control-center/staff-invitations', [
            'email' => 'invitee@example.test',
            'role' => 'ops_support',
        ])->assertCreated();
        $token = null;
        Notification::assertSentOnDemand(
            ControlCenterStaffInvitationNotification::class,
            function (ControlCenterStaffInvitationNotification $notification) use (&$token): bool {
                $token = $notification->token;

                return true;
            },
        );

        config(['control_center.staff_roles' => ['removed-invite-admin@example.test' => 'ops_readonly']]);
        $this->postJson('/api/v1/internal/control-center/staff-invitations/accept', [
            'email' => 'invitee@example.test',
            'name' => 'Invitee',
            'token' => $token,
            'password' => 'Another-long-staff-password-39',
            'password_confirmation' => 'Another-long-staff-password-39',
        ])->assertUnprocessable();

        $this->assertDatabaseMissing('users', ['email' => 'invitee@example.test']);
    }

    public function test_staff_admin_cannot_revoke_the_last_active_database_admin(): void
    {
        $admin = $this->staffFor('db-admin@example.test');
        $member = StaffMember::query()->create([
            'user_id' => $admin->id,
            'role' => 'ops_admin',
            'status' => 'active',
            'provisioned_by_user_id' => $admin->id,
        ]);
        config(['control_center.staff_emails' => []]);
        config(['control_center.staff_roles' => []]);
        Sanctum::actingAs($admin);

        $this->patchJson('/api/v1/internal/control-center/staff/'.$member->id, [
            'status' => 'revoked',
        ])->assertUnprocessable();

        $this->assertDatabaseHas('control_center_staff', [
            'id' => $member->id,
            'role' => 'ops_admin',
            'status' => 'active',
        ]);
    }

    public function test_admin_without_verified_email_and_mfa_does_not_count_as_a_viable_backup_admin(): void
    {
        $admin = $this->staffFor('effective-admin@example.test');
        $adminMember = StaffMember::query()->create([
            'user_id' => $admin->id,
            'role' => 'ops_admin',
            'status' => 'active',
            'provisioned_by_user_id' => $admin->id,
        ]);
        $inactiveAdmin = User::factory()->create([
            'email' => 'inactive-admin@example.test',
            'email_verified_at' => null,
        ]);
        StaffMember::query()->create([
            'user_id' => $inactiveAdmin->id,
            'role' => 'ops_admin',
            'status' => 'active',
            'provisioned_by_user_id' => $admin->id,
        ]);
        config([
            'control_center.staff_emails' => ['configured-but-missing@example.test'],
            'control_center.staff_roles' => ['configured-but-missing@example.test' => 'ops_admin'],
        ]);
        Sanctum::actingAs($admin);

        $this->patchJson('/api/v1/internal/control-center/staff/'.$adminMember->id, [
            'status' => 'revoked',
        ])->assertUnprocessable();

        $this->assertDatabaseHas('control_center_staff', [
            'id' => $adminMember->id,
            'status' => 'active',
        ]);
    }

    public function test_new_staff_can_only_access_mfa_setup_until_their_mfa_is_verified(): void
    {
        $admin = $this->staffFor('bootstrap-admin@example.test');
        $newStaff = User::factory()->create(['email' => 'pending-staff@example.test']);
        config(['control_center.staff_emails' => ['bootstrap-admin@example.test']]);
        config(['control_center.staff_roles' => ['bootstrap-admin@example.test' => 'ops_admin']]);
        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/internal/control-center/staff', [
            'email' => $newStaff->email,
            'role' => 'ops_support',
        ])->assertCreated()->assertJsonPath('data.staff.status', 'pending_mfa');

        Sanctum::actingAs($newStaff);
        $this->getJson('/api/v1/internal/control-center/staff-preflight')
            ->assertOk()
            ->assertJsonPath('data.mfa_enabled', false);
        $this->getJson('/api/v1/internal/control-center/session')->assertForbidden();

        $newStaff->forceFill([
            'two_factor_secret' => 'new-staff-mfa-secret',
            'two_factor_confirmed_at' => now(),
        ])->save();
        $this->postJson('/api/v1/internal/control-center/staff-mfa-complete')
            ->assertOk()
            ->assertJsonPath('data.active', true);
        $this->getJson('/api/v1/internal/control-center/session')
            ->assertOk()
            ->assertJsonPath('data.role', 'ops_support');
    }

    private function organizationFor(User $owner): Organization
    {
        $organization = Organization::create([
            'name' => 'Safe Directory Company',
            'legal_name' => 'Internal Legal Name',
            'status' => 'trialing',
        ]);
        $organization->users()->attach($owner->id, ['role' => 'owner']);

        return $organization;
    }

    private function staffFor(string $email): User
    {
        $staff = User::factory()->create(['email' => $email]);
        $staff->forceFill([
            'two_factor_secret' => 'test-staff-mfa-secret',
            'two_factor_confirmed_at' => now(),
        ])->save();

        return $staff;
    }
}
