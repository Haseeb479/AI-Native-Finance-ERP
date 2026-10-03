<?php

namespace Tests\Feature;

use App\Domain\Accounting\ChartOfAccounts\Models\Account;
use App\Domain\Accounting\ChartOfAccounts\Templates\PakistanSmeChartTemplate;
use App\Domain\Accounting\Journal\Models\JournalEntry;
use App\Domain\Accounting\Period\Models\AccountingPeriod;
use App\Domain\Accounting\Period\Models\FiscalYear;
use App\Domain\Accounting\Period\Services\PeriodManager;
use App\Domain\Banking\Models\BankAccount;
use App\Domain\Documents\Models\Document;
use App\Domain\Organization\Models\Organization;
use App\Domain\Purchasing\Models\PurchaseBill;
use App\Domain\Purchasing\Models\Vendor;
use App\Domain\Sales\Models\Customer;
use App\Domain\Sales\Models\SalesInvoice;
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

    private function postWithToken(string $token, string $uri, array $data = [])
    {
        auth()->forgetGuards();
        return $this->withHeader('Authorization', "Bearer {$token}")->postJson($uri, $data);
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

    public function test_tenant_isolation_on_sales_invoices(): void
    {
        $custA = Customer::create([
            'organization_id' => $this->orgA->id,
            'name' => 'Alpha Customer Invoicing',
            'email' => 'invoicing@alpha.test',
        ]);

        $invoiceA = SalesInvoice::create([
            'organization_id' => $this->orgA->id,
            'customer_id' => $custA->id,
            'invoice_number' => 'INV-ALPHA-999',
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => 'draft',
            'currency' => 'PKR',
            'subtotal' => 10000.00,
            'tax_amount' => 1800.00,
            'total_amount' => 11800.00,
            'created_by' => $this->userA->id,
        ]);

        // User A reads Org A invoice -> 200
        $resA = $this->getWithToken($this->tokenA, "/api/v1/organizations/{$this->orgA->id}/invoices/{$invoiceA->id}");
        $resA->assertOk();

        // User B attempts direct cross-tenant GET on Org A -> Denied (403/404)
        $resCrossOrg = $this->getWithToken($this->tokenB, "/api/v1/organizations/{$this->orgA->id}/invoices/{$invoiceA->id}");
        $this->assertTrue(in_array($resCrossOrg->status(), [403, 404]));

        // User B attempts IDOR via Org B with Org A invoice ID -> 404 Not Found
        $resIdor = $this->getWithToken($this->tokenB, "/api/v1/organizations/{$this->orgB->id}/invoices/{$invoiceA->id}");
        $this->assertTrue(in_array($resIdor->status(), [403, 404]));
    }

    public function test_tenant_isolation_on_purchase_bills(): void
    {
        $vendorA = Vendor::create([
            'organization_id' => $this->orgA->id,
            'name' => 'Alpha Raw Materials Supplier',
        ]);

        $billA = PurchaseBill::create([
            'organization_id' => $this->orgA->id,
            'vendor_id' => $vendorA->id,
            'bill_number' => 'BILL-ALPHA-999',
            'bill_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => 'draft',
            'currency' => 'PKR',
            'subtotal' => 5000.00,
            'tax_amount' => 900.00,
            'total_amount' => 5900.00,
            'net_payable' => 5900.00,
            'created_by' => $this->userA->id,
        ]);

        // User A reads Org A bill -> 200
        $resA = $this->getWithToken($this->tokenA, "/api/v1/organizations/{$this->orgA->id}/bills/{$billA->id}");
        $resA->assertOk();

        // User B attempts direct cross-tenant GET on Org A -> Denied (403/404)
        $resCrossOrg = $this->getWithToken($this->tokenB, "/api/v1/organizations/{$this->orgA->id}/bills/{$billA->id}");
        $this->assertTrue(in_array($resCrossOrg->status(), [403, 404]));

        // User B attempts IDOR via Org B with Org A bill ID -> 404 Not Found
        $resIdor = $this->getWithToken($this->tokenB, "/api/v1/organizations/{$this->orgB->id}/bills/{$billA->id}");
        $this->assertTrue(in_array($resIdor->status(), [403, 404]));
    }

    public function test_tenant_isolation_on_journals(): void
    {
        $periodA = AccountingPeriod::where('organization_id', $this->orgA->id)->firstOrFail();

        $journalA = JournalEntry::create([
            'organization_id' => $this->orgA->id,
            'accounting_period_id' => $periodA->id,
            'entry_number' => 'JE-ALPHA-ISO-01',
            'entry_date' => now()->toDateString(),
            'description' => 'Test isolation journal entry',
            'status' => 'draft',
            'currency' => 'PKR',
            'total_amount' => 1000.00,
            'created_by' => $this->userA->id,
        ]);

        // User A reads Org A journal -> 200
        $resA = $this->getWithToken($this->tokenA, "/api/v1/organizations/{$this->orgA->id}/journals/{$journalA->id}");
        $resA->assertOk();

        // User B attempts cross-tenant GET on Org A -> Denied (403/404)
        $resCrossOrg = $this->getWithToken($this->tokenB, "/api/v1/organizations/{$this->orgA->id}/journals/{$journalA->id}");
        $this->assertTrue(in_array($resCrossOrg->status(), [403, 404]));

        // User B attempts IDOR via Org B with Org A journal ID -> 404 Not Found
        $resIdor = $this->getWithToken($this->tokenB, "/api/v1/organizations/{$this->orgB->id}/journals/{$journalA->id}");
        $this->assertTrue(in_array($resIdor->status(), [403, 404]));
    }

    public function test_tenant_isolation_on_documents(): void
    {
        $docA = Document::create([
            'organization_id' => $this->orgA->id,
            'uploaded_by' => $this->userA->id,
            'document_number' => 'DOC-ALPHA-SECRET-01',
            'document_type' => 'invoice',
            'original_filename' => 'board_resolutions.pdf',
            'storage_disk' => 'local',
            'storage_path' => 'documents/alpha_secret.pdf',
            'mime_type' => 'application/pdf',
            'file_size_bytes' => 2048,
        ]);

        // User A reads Org A document -> 200
        $resA = $this->getWithToken($this->tokenA, "/api/v1/organizations/{$this->orgA->id}/documents/{$docA->id}");
        $resA->assertOk();

        // User B attempts cross-tenant GET on Org A -> Denied (403/404)
        $resCrossOrg = $this->getWithToken($this->tokenB, "/api/v1/organizations/{$this->orgA->id}/documents/{$docA->id}");
        $this->assertTrue(in_array($resCrossOrg->status(), [403, 404]));

        // User B attempts IDOR via Org B with Org A document ID -> 404 Not Found
        $resIdor = $this->getWithToken($this->tokenB, "/api/v1/organizations/{$this->orgB->id}/documents/{$docA->id}");
        $this->assertTrue(in_array($resIdor->status(), [403, 404]));
    }

    public function test_tenant_isolation_on_ai_copilot_gateway(): void
    {
        // User B attempts to access AI copilot on Org A -> Denied (403 Forbidden)
        $resCrossAi = $this->postWithToken($this->tokenB, "/api/v1/organizations/{$this->orgA->id}/ai/copilot/qa", [
            'query' => 'Reveal general ledger balances',
        ]);
        $this->assertTrue(in_array($resCrossAi->status(), [403, 404]));
    }
}
