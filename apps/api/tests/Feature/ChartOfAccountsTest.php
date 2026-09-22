<?php

namespace Tests\Feature;

use App\Domain\Accounting\ChartOfAccounts\Models\Account;
use App\Domain\Accounting\ChartOfAccounts\Models\AccountType;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Database\Seeders\AccountTypeSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChartOfAccountsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Organization $org;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);
        $this->seed(AccountTypeSeeder::class);

        $this->owner = User::factory()->create([
            'name' => 'Fatima Noor',
            'email' => 'fatima@pakfintech.pk',
        ]);

        $this->token = $this->owner->createToken('test-token')->plainTextToken;

        $this->org = Organization::create([
            'name' => 'Pak FinTech Corp',
            'legal_name' => 'Pak FinTech Private Limited',
            'ntn' => '7654321-0',
            'base_currency' => 'PKR',
        ]);

        $this->org->users()->attach($this->owner->id, ['role' => 'owner', 'is_default' => true]);

        \App\Domain\Accounting\ChartOfAccounts\Templates\PakistanSmeChartTemplate::seedForOrganization($this->org);
    }

    public function test_can_list_account_types_and_groups(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/v1/account-types');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'name',
                        'slug',
                        'classification',
                        'normal_balance',
                        'groups' => [
                            '*' => ['id', 'name', 'slug'],
                        ],
                    ],
                ],
            ]);

        $this->assertCount(5, $response->json('data'));
    }

    public function test_organization_has_seeded_pakistan_sme_chart(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson("/api/v1/organizations/{$this->org->id}/accounts");

        $response->assertStatus(200);
        $accounts = $response->json('data');

        $this->assertGreaterThanOrEqual(20, count($accounts));

        // Verify key Pakistan SME accounts exist
        $codes = collect($accounts)->pluck('code')->all();
        $this->assertContains('1010', $codes); // Cash in Hand
        $this->assertContains('1020', $codes); // Meezan Bank
        $this->assertContains('1030', $codes); // Trade Debtors
        $this->assertContains('1040', $codes); // WHT Receivable
        $this->assertContains('2010', $codes); // Trade Creditors
        $this->assertContains('2020', $codes); // Sales Tax Payable
        $this->assertContains('3030', $codes); // Retained Earnings
        $this->assertContains('4010', $codes); // Sales Revenue
        $this->assertContains('6010', $codes); // Salaries Expense
    }

    public function test_user_can_create_custom_account(): void
    {
        $assetType = AccountType::where('slug', 'asset')->firstOrFail();
        $bankGroup = $assetType->groups()->where('slug', 'bank-cash')->firstOrFail();

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson("/api/v1/organizations/{$this->org->id}/accounts", [
                'code' => '1025',
                'name' => 'Faysal Bank Islamic Account',
                'description' => 'Secondary operating account',
                'account_type_id' => $assetType->id,
                'account_group_id' => $bankGroup->id,
                'classification' => 'asset',
                'normal_balance' => 'debit',
                'is_reconcilable' => true,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.code', '1025')
            ->assertJsonPath('data.name', 'Faysal Bank Islamic Account')
            ->assertJsonPath('data.normal_balance', 'debit');

        $this->assertDatabaseHas('accounts', [
            'organization_id' => $this->org->id,
            'code' => '1025',
            'name' => 'Faysal Bank Islamic Account',
        ]);
    }

    public function test_can_create_child_account_linked_to_parent(): void
    {
        $parentAccount = Account::where('organization_id', $this->org->id)
            ->where('code', '1020')
            ->firstOrFail();

        $assetType = AccountType::where('slug', 'asset')->firstOrFail();

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson("/api/v1/organizations/{$this->org->id}/accounts", [
                'code' => '1020-01',
                'name' => 'Meezan - Clifton Sub Branch Account',
                'account_type_id' => $assetType->id,
                'parent_account_id' => $parentAccount->id,
                'classification' => 'asset',
                'normal_balance' => 'debit',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.parent_account_id', $parentAccount->id);

        $this->assertDatabaseHas('accounts', [
            'organization_id' => $this->org->id,
            'code' => '1020-01',
            'parent_account_id' => $parentAccount->id,
        ]);
    }

    public function test_cannot_create_duplicate_account_code_in_same_organization(): void
    {
        $assetType = AccountType::where('slug', 'asset')->firstOrFail();

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson("/api/v1/organizations/{$this->org->id}/accounts", [
                'code' => '1010', // Already exists in seeded SME chart
                'name' => 'Duplicate Cash Account',
                'account_type_id' => $assetType->id,
                'classification' => 'asset',
                'normal_balance' => 'debit',
            ]);

        $response->assertStatus(422);
    }

    public function test_can_reuse_same_account_code_in_different_organizations(): void
    {
        $otherOwner = User::factory()->create(['email' => 'other@corp.pk']);
        $otherToken = $otherOwner->createToken('other-token')->plainTextToken;

        $otherOrgResponse = $this->withHeader('Authorization', "Bearer {$otherToken}")
            ->postJson('/api/v1/organizations', [
                'name' => 'Lahore Retail Ltd',
                'legal_name' => 'Lahore Retail Limited',
                'provision_default_chart' => false, // Start empty
            ]);

        $otherOrgId = $otherOrgResponse->json('data.organization.id');
        $assetType = AccountType::where('slug', 'asset')->firstOrFail();

        // Should successfully create 1010 in other org even though $this->org has 1010
        $response = $this->withHeader('Authorization', "Bearer {$otherToken}")
            ->postJson("/api/v1/organizations/{$otherOrgId}/accounts", [
                'code' => '1010',
                'name' => 'Lahore Drawer Cash',
                'account_type_id' => $assetType->id,
                'classification' => 'asset',
                'normal_balance' => 'debit',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.code', '1010');
    }

    public function test_account_filtering_by_classification(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson("/api/v1/organizations/{$this->org->id}/accounts?classification=revenue");

        $response->assertStatus(200);
        $accounts = $response->json('data');

        $this->assertNotEmpty($accounts);
        foreach ($accounts as $acc) {
            $this->assertEquals('revenue', $acc['classification']);
        }
    }

    public function test_user_can_update_account(): void
    {
        $account = Account::where('organization_id', $this->org->id)
            ->where('code', '6020')
            ->firstOrFail();

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->putJson("/api/v1/organizations/{$this->org->id}/accounts/{$account->id}", [
                'name' => 'Gulberg Office Rent Expense',
                'description' => 'Updated rent description for Lahore office',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.name', 'Gulberg Office Rent Expense');

        $this->assertDatabaseHas('accounts', [
            'id' => $account->id,
            'name' => 'Gulberg Office Rent Expense',
        ]);
    }

    public function test_cannot_delete_or_archive_system_account(): void
    {
        $systemAccount = Account::where('organization_id', $this->org->id)
            ->where('is_system', true)
            ->firstOrFail();

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->deleteJson("/api/v1/organizations/{$this->org->id}/accounts/{$systemAccount->id}");

        $response->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'SYSTEM_ACCOUNT_PROTECTED');
    }

    public function test_can_archive_custom_account(): void
    {
        $assetType = AccountType::where('slug', 'asset')->firstOrFail();

        $createResponse = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson("/api/v1/organizations/{$this->org->id}/accounts", [
                'code' => '9999',
                'name' => 'Temporary Suspense Account',
                'account_type_id' => $assetType->id,
                'classification' => 'asset',
                'normal_balance' => 'debit',
            ]);

        $accountId = $createResponse->json('data.id');

        $deleteResponse = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->deleteJson("/api/v1/organizations/{$this->org->id}/accounts/{$accountId}");

        $deleteResponse->assertStatus(200)
            ->assertJsonPath('data.archived', true);

        // Account is soft deleted and marked inactive
        $this->assertSoftDeleted('accounts', ['id' => $accountId]);
    }

    public function test_tenant_isolation_prevents_access_to_another_organizations_accounts(): void
    {
        $intruder = User::factory()->create(['email' => 'intruder@external.pk']);
        $intruderToken = $intruder->createToken('intruder-token')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$intruderToken}")
            ->getJson("/api/v1/organizations/{$this->org->id}/accounts");

        // Intruder does not belong to $this->org
        $response->assertStatus(404);
    }
}
