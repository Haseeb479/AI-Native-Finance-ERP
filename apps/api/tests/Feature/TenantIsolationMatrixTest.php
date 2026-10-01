<?php

namespace Tests\Feature;

use App\Domain\Accounting\ChartOfAccounts\Models\Account;
use App\Domain\Accounting\ChartOfAccounts\Templates\PakistanSmeChartTemplate;
use App\Domain\Accounting\Period\Services\PeriodManager;
use App\Domain\Banking\Models\BankAccount;
use App\Domain\Organization\Models\Organization;
use App\Domain\Purchasing\Models\Vendor;
use App\Domain\Sales\Models\Customer;
use App\Models\User;
use Database\Seeders\AccountTypeSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantIsolationMatrixTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;
    private Organization $orgB;
    private User $userA;
    private User $userB;
    private string $tokenA;
    private string $tokenB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);
        $this->seed(AccountTypeSeeder::class);

        $this->userA = User::factory()->create(['name' => 'Alice Alpha', 'email' => 'alice@alpha.test']);
        $this->tokenA = $this->userA->createToken('test')->plainTextToken;

        $this->userB = User::factory()->create(['name' => 'Bob Beta', 'email' => 'bob@beta.test']);
        $this->tokenB = $this->userB->createToken('test')->plainTextToken;

        $this->orgA = Organization::create([
            'name' => 'Alpha Corp',
            'legal_name' => 'Alpha Corporation (Pvt) Ltd',
            'base_currency' => 'PKR',
            'fiscal_year_start_month' => 7,
        ]);
        $this->orgA->users()->attach($this->userA->id, ['role' => 'owner', 'is_default' => true]);

        $this->orgB = Organization::create([
            'name' => 'Beta Corp',
            'legal_name' => 'Beta Corporation (Pvt) Ltd',
            'base_currency' => 'PKR',
            'fiscal_year_start_month' => 7,
        ]);
        $this->orgB->users()->attach($this->userB->id, ['role' => 'owner', 'is_default' => true]);

        PakistanSmeChartTemplate::seedForOrganization($this->orgA);
        PakistanSmeChartTemplate::seedForOrganization($this->orgB);

        app(PeriodManager::class)->generateFiscalYear($this->orgA, 2025);
        app(PeriodManager::class)->generateFiscalYear($this->orgB, 2025);
    }

    private function getWithToken(string $token, string $uri)
    {
        auth()->forgetGuards();
        return $this->withHeader('Authorization', "Bearer {$token}")->getJson($uri);
    }

    public function test_tenant_isolation_on_accounts(): void
    {
        // User A querying Org A accounts -> 200 OK
        $resA = $this->getWithToken($this->tokenA, "/api/v1/organizations/{$this->orgA->id}/accounts");
        $resA->assertOk();

        // User A querying Org B accounts -> Denied (404/403)
        $resCrossAB = $this->getWithToken($this->tokenA, "/api/v1/organizations/{$this->orgB->id}/accounts");
        $this->assertTrue(in_array($resCrossAB->status(), [403, 404]));

        // User B querying Org A accounts -> Denied (403/404)
        $resCrossBA = $this->getWithToken($this->tokenB, "/api/v1/organizations/{$this->orgA->id}/accounts");
        $this->assertTrue(in_array($resCrossBA->status(), [403, 404]));
    }

    public function test_tenant_isolation_on_customers(): void
    {
        Customer::create([
            'organization_id' => $this->orgA->id,
            'name' => 'Customer Alpha One',
            'email' => 'client1@alpha.test',
        ]);

        Customer::create([
            'organization_id' => $this->orgB->id,
            'name' => 'Customer Beta One',
            'email' => 'client1@beta.test',
        ]);

        // User A list customers in Org A -> Allowed, sees only Alpha customer
        $resA = $this->getWithToken($this->tokenA, "/api/v1/organizations/{$this->orgA->id}/customers");
        $resA->assertOk();
        $customersA = $resA->json('data');
        $this->assertCount(1, $customersA);
        $this->assertEquals('Customer Alpha One', $customersA[0]['name']);

        // User A accessing Org B customer endpoint -> Denied
        $resCross = $this->getWithToken($this->tokenA, "/api/v1/organizations/{$this->orgB->id}/customers");
        $this->assertTrue(in_array($resCross->status(), [403, 404]));
    }

    public function test_tenant_isolation_on_vendors(): void
    {
        Vendor::create([
            'organization_id' => $this->orgA->id,
            'name' => 'Vendor Alpha One',
        ]);

        $resA = $this->getWithToken($this->tokenA, "/api/v1/organizations/{$this->orgA->id}/vendors");
        $resA->assertOk();

        $resCross = $this->getWithToken($this->tokenB, "/api/v1/organizations/{$this->orgA->id}/vendors");
        $this->assertTrue(in_array($resCross->status(), [403, 404]));
    }

    public function test_tenant_isolation_on_banking(): void
    {
        $cashAccA = Account::where('organization_id', $this->orgA->id)->where('code', '1010')->firstOrFail();

        $bankA = BankAccount::create([
            'organization_id' => $this->orgA->id,
            'account_id' => $cashAccA->id,
            'account_title' => 'Alpha Operating Bank',
            'account_number' => 'PK00ALPH12345',
            'bank_name' => 'Meezan Bank',
            'currency' => 'PKR',
            'current_balance' => 100000.00,
        ]);

        // User A accesses Org A bank -> 200
        $resA = $this->getWithToken($this->tokenA, "/api/v1/organizations/{$this->orgA->id}/bank-accounts/{$bankA->id}");
        $resA->assertOk();

        // User B accesses Org A bank -> Denied
        $resCross = $this->getWithToken($this->tokenB, "/api/v1/organizations/{$this->orgA->id}/bank-accounts/{$bankA->id}");
        $this->assertTrue(in_array($resCross->status(), [403, 404]));
    }

    public function test_tenant_isolation_on_reports(): void
    {
        // User A views Org A trial balance -> 200
        $resA = $this->getWithToken($this->tokenA, "/api/v1/organizations/{$this->orgA->id}/reports/trial-balance");
        $resA->assertOk();

        // User A views Org B trial balance -> Denied
        $resCross = $this->getWithToken($this->tokenA, "/api/v1/organizations/{$this->orgB->id}/reports/trial-balance");
        $this->assertTrue(in_array($resCross->status(), [403, 404]));
    }

    public function test_tenant_isolation_on_audit_logs(): void
    {
        // User A views Org A audit logs -> 200
        $resA = $this->getWithToken($this->tokenA, "/api/v1/organizations/{$this->orgA->id}/audit-logs");
        $resA->assertOk();

        // User B views Org A audit logs -> Denied
        $resCross = $this->getWithToken($this->tokenB, "/api/v1/organizations/{$this->orgA->id}/audit-logs");
        $this->assertTrue(in_array($resCross->status(), [403, 404]));
    }
}
