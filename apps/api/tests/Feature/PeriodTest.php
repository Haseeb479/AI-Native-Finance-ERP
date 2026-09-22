<?php

namespace Tests\Feature;

use App\Domain\Accounting\Period\Models\AccountingPeriod;
use App\Domain\Accounting\Period\Models\FiscalYear;
use App\Domain\Accounting\Period\Services\PeriodManager;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Database\Seeders\AccountTypeSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PeriodTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Organization $org;
    private string $token;
    private PeriodManager $periodManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);
        $this->seed(AccountTypeSeeder::class);

        $this->periodManager = app(PeriodManager::class);

        $this->owner = User::factory()->create([
            'name' => 'Tariq Mehmood',
            'email' => 'tariq@textiles.pk',
        ]);

        $this->token = $this->owner->createToken('test-token')->plainTextToken;

        $this->org = Organization::create([
            'name' => 'Tariq Textiles Ltd',
            'legal_name' => 'Tariq Textiles (Pvt) Limited',
            'base_currency' => 'PKR',
            'fiscal_year_start_month' => 7, // July
        ]);

        $this->org->users()->attach($this->owner->id, ['role' => 'owner', 'is_default' => true]);
    }

    public function test_auto_generates_pakistan_fiscal_year_and_12_monthly_periods(): void
    {
        $fy = $this->periodManager->generateFiscalYear($this->org, 2025);

        $this->assertEquals('FY 2025-2026', $fy->name);
        $this->assertEquals('2025-07-01', $fy->start_date->toDateString());
        $this->assertEquals('2026-06-30', $fy->end_date->toDateString());
        $this->assertCount(12, $fy->periods);

        $firstPeriod = $fy->periods->first();
        $this->assertEquals(1, $firstPeriod->period_number);
        $this->assertEquals('July 2025', $firstPeriod->name);
        $this->assertEquals('2025-07-01', $firstPeriod->start_date->toDateString());
        $this->assertEquals('2025-07-31', $firstPeriod->end_date->toDateString());
        $this->assertEquals('open', $firstPeriod->status);

        $lastPeriod = $fy->periods->last();
        $this->assertEquals(12, $lastPeriod->period_number);
        $this->assertEquals('June 2026', $lastPeriod->name);
        $this->assertEquals('2026-06-01', $lastPeriod->start_date->toDateString());
        $this->assertEquals('2026-06-30', $lastPeriod->end_date->toDateString());
    }

    public function test_organization_creation_auto_provisions_fiscal_year(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/v1/organizations', [
                'name' => 'Karachi Logistics',
                'legal_name' => 'Karachi Logistics (Pvt) Ltd',
                'fiscal_year_start_month' => 7,
            ]);

        $response->assertStatus(201);
        $orgId = $response->json('data.organization.id');

        $this->assertDatabaseHas('fiscal_years', [
            'organization_id' => $orgId,
        ]);

        $periodsCount = AccountingPeriod::where('organization_id', $orgId)->count();
        $this->assertEquals(12, $periodsCount);
    }

    public function test_can_find_open_period_for_specific_date(): void
    {
        $this->periodManager->generateFiscalYear($this->org, 2025);

        // Transaction date in October 2025
        $period = $this->periodManager->getOpenPeriodForDate($this->org, '2025-10-15');

        $this->assertNotNull($period);
        $this->assertEquals('October 2025', $period->name);
        $this->assertEquals(4, $period->period_number); // July=1, Aug=2, Sept=3, Oct=4
        $this->assertTrue($period->isOpen());
        $this->assertTrue($period->canPost());
    }

    public function test_authorized_user_can_close_period(): void
    {
        $fy = $this->periodManager->generateFiscalYear($this->org, 2025);
        $period = $fy->periods->first();

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson("/api/v1/organizations/{$this->org->id}/periods/{$period->id}/close");

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'closed');

        $period->refresh();
        $this->assertTrue($period->isClosed());
        $this->assertFalse($period->canPost());
        $this->assertEquals($this->owner->id, $period->closed_by);
        $this->assertNotNull($period->closed_at);
    }

    public function test_authorized_user_can_reopen_closed_period_with_audit_reason(): void
    {
        $fy = $this->periodManager->generateFiscalYear($this->org, 2025);
        $period = $fy->periods->first();
        $this->periodManager->closePeriod($period, $this->owner);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson("/api/v1/organizations/{$this->org->id}/periods/{$period->id}/reopen", [
                'reason' => 'Adjustment for late supplier invoice received for July audit.',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'open');

        $period->refresh();
        $this->assertTrue($period->isOpen());
        $this->assertTrue($period->canPost());
        $this->assertEquals('Adjustment for late supplier invoice received for July audit.', $period->reopen_reason);
        $this->assertEquals($this->owner->id, $period->reopened_by);
    }

    public function test_cannot_reopen_period_without_valid_reason(): void
    {
        $fy = $this->periodManager->generateFiscalYear($this->org, 2025);
        $period = $fy->periods->first();
        $this->periodManager->closePeriod($period, $this->owner);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson("/api/v1/organizations/{$this->org->id}/periods/{$period->id}/reopen", [
                'reason' => 'short', // Fails min:10 rule
            ]);

        $response->assertStatus(422);
    }

    public function test_authorized_user_can_lock_period(): void
    {
        $fy = $this->periodManager->generateFiscalYear($this->org, 2025);
        $period = $fy->periods->first();

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson("/api/v1/organizations/{$this->org->id}/periods/{$period->id}/lock");

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'locked');

        $period->refresh();
        $this->assertTrue($period->isLocked());
        $this->assertFalse($period->canPost());
    }

    public function test_staff_without_permission_cannot_close_or_reopen_period(): void
    {
        $staff = User::factory()->create(['email' => 'staff@textiles.pk']);
        $staffToken = $staff->createToken('staff-token')->plainTextToken;
        $this->org->users()->attach($staff->id, ['role' => 'staff']);

        $fy = $this->periodManager->generateFiscalYear($this->org, 2025);
        $period = $fy->periods->first();

        $response = $this->withHeader('Authorization', "Bearer {$staffToken}")
            ->postJson("/api/v1/organizations/{$this->org->id}/periods/{$period->id}/close");

        $response->assertStatus(403)
            ->assertJsonPath('errors.0.code', 'FORBIDDEN');
    }

    public function test_tenant_isolation_prevents_access_to_another_tenants_periods(): void
    {
        $intruder = User::factory()->create(['email' => 'intruder@other.pk']);
        $intruderToken = $intruder->createToken('intruder-token')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$intruderToken}")
            ->getJson("/api/v1/organizations/{$this->org->id}/periods");

        $response->assertStatus(404);
    }
}
