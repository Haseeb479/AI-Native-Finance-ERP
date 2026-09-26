<?php

namespace Tests\Feature;

use App\Domain\Accounting\ChartOfAccounts\Models\Account;
use App\Domain\Accounting\ChartOfAccounts\Templates\PakistanSmeChartTemplate;
use App\Domain\Organization\Context\TenantContext;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Scopes\TenantScope;
use App\Models\User;
use Database\Seeders\AccountTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantContextAndIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;
    private Organization $orgB;
    private User $userA;
    private TenantContext $context;

    protected function setUp(): void
    {
        parent::setUp();

        (new AccountTypeSeeder())->run();

        $this->orgA = Organization::create([
            'name' => 'Tenant Alpha',
            'slug' => 'tenant-alpha',
            'legal_name' => 'Tenant Alpha Ltd',
            'country_code' => 'PK',
            'base_currency' => 'PKR',
        ]);

        $this->orgB = Organization::create([
            'name' => 'Tenant Beta',
            'slug' => 'tenant-beta',
            'legal_name' => 'Tenant Beta Ltd',
            'country_code' => 'PK',
            'base_currency' => 'PKR',
        ]);

        $this->userA = User::factory()->create();
        $this->orgA->users()->attach($this->userA, ['role' => 'admin', 'is_default' => true]);

        PakistanSmeChartTemplate::seedForOrganization($this->orgA);
        PakistanSmeChartTemplate::seedForOrganization($this->orgB);

        $this->context = app(TenantContext::class);
        $this->context->clear();
    }

    protected function tearDown(): void
    {
        $this->context->clear();
        parent::tearDown();
    }

    public function test_tenant_context_binds_and_retrieves_organization(): void
    {
        $this->context->setOrganization($this->orgA);
        $this->context->setUser($this->userA);
        $this->context->setEntityId('entity-123');

        $this->assertEquals($this->orgA->id, $this->context->getOrganizationId());
        $this->assertEquals($this->orgA->id, $this->context->getOrganization()?->id);
        $this->assertEquals($this->userA->id, $this->context->getUser()?->id);
        $this->assertEquals('entity-123', $this->context->getEntityId());
    }

    public function test_tenant_scope_filters_queries_based_on_tenant_context(): void
    {
        // Without tenant context, all accounts across orgs exist
        $this->context->clear();
        $allCount = Account::withoutGlobalScopes()->count();
        $this->assertGreaterThan(0, $allCount);

        // When Tenant Context is set to Org A
        $this->context->setOrganization($this->orgA);
        $orgACount = Account::count(); // Global scope applies

        // When Tenant Context is set to Org B
        $this->context->setOrganization($this->orgB);
        $orgBCount = Account::count();

        $this->assertGreaterThan(0, $orgACount);
        $this->assertGreaterThan(0, $orgBCount);
        $this->assertEquals($allCount, $orgACount + $orgBCount);
    }

    public function test_tenant_context_prevents_worker_tenant_bleed_on_clear(): void
    {
        // Simulate Job 1 running under Org A
        $this->context->setOrganization($this->orgA);
        $this->context->setUser($this->userA);
        $this->assertEquals($this->orgA->id, TenantScope::getOrganizationId());

        // Worker finishes Job 1 and clears context
        $this->context->clear();

        // Verify state did not bleed into worker
        $this->assertNull($this->context->getOrganizationId());
        $this->assertNull($this->context->getUser());
        $this->assertNull($this->context->getEntityId());
        $this->assertEmpty($this->context->getPermissions());
        $this->assertNull(TenantScope::getOrganizationId());

        // Simulate Job 2 running under Org B
        $this->context->setOrganization($this->orgB);
        $this->assertEquals($this->orgB->id, $this->context->getOrganizationId());
        $this->assertEquals($this->orgB->id, TenantScope::getOrganizationId());
    }
}
