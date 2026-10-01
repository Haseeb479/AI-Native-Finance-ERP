<?php

namespace Tests\Feature;

use App\Domain\Accounting\ChartOfAccounts\Models\Account;
use App\Domain\Accounting\Period\Models\AccountingPeriod;
use App\Domain\Accounting\Period\Services\PeriodManager;
use App\Domain\Close\Models\FixedAsset;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Database\Seeders\AccountTypeSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FixedAssetLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Organization $org;
    private string $token;
    private AccountingPeriod $period;

    private Account $assetAccount;
    private Account $accumDeprAccount;
    private Account $deprExpenseAccount;
    private Account $cashAccount;
    private Account $gainLossAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);
        $this->seed(AccountTypeSeeder::class);

        $this->owner = User::factory()->create([
            'name'  => 'Asset Accountant',
            'email' => 'asset@enterprise.test',
        ]);
        $this->token = $this->owner->createToken('test')->plainTextToken;

        $this->org = Organization::create([
            'name'                    => 'Acme Industrial',
            'legal_name'              => 'Acme Industrial Corp',
            'base_currency'           => 'PKR',
            'fiscal_year_start_month' => 7,
        ]);
        $this->org->users()->attach($this->owner->id, ['role' => 'owner', 'is_default' => true]);

        app(PeriodManager::class)->generateFiscalYear($this->org, 2025);
        \App\Domain\Accounting\ChartOfAccounts\Templates\PakistanSmeChartTemplate::seedForOrganization($this->org);

        $this->period = AccountingPeriod::where('organization_id', $this->org->id)
            ->where('status', 'open')
            ->firstOrFail();

        $this->assetAccount = Account::where('organization_id', $this->org->id)->where('code', '1510')->firstOrFail();
        $this->accumDeprAccount = Account::where('organization_id', $this->org->id)->where('code', '1590')->firstOrFail();
        $this->deprExpenseAccount = Account::where('organization_id', $this->org->id)->where('code', '6070')->firstOrFail();
        $this->cashAccount = Account::where('organization_id', $this->org->id)->where('code', '1010')->firstOrFail();
        $this->gainLossAccount = Account::where('organization_id', $this->org->id)->where('code', '6080')->firstOrFail();
    }

    public function test_can_register_and_depreciate_fixed_asset(): void
    {
        // 1. Create Fixed Asset
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson("/api/v1/organizations/{$this->org->id}/fixed-assets", [
                'name' => 'Laser Cutting Machine',
                'asset_number' => 'FA-LCM-001',
                'asset_account_id' => $this->assetAccount->id,
                'accumulated_depreciation_account_id' => $this->accumDeprAccount->id,
                'depreciation_expense_account_id' => $this->deprExpenseAccount->id,
                'purchase_date' => '2025-07-01',
                'purchase_cost' => 60000.00,
                'salvage_value' => 0.00,
                'useful_life_months' => 60,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.name', 'Laser Cutting Machine');

        $assetId = $response->json('data.id');
        $this->assertDatabaseHas('fixed_assets', ['id' => $assetId, 'status' => 'active']);

        // 2. Run monthly depreciation schedule for period
        $deprResponse = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson("/api/v1/organizations/{$this->org->id}/close-cycles/{$this->period->id}/depreciation");

        $deprResponse->assertOk()
            ->assertJsonPath('data.depreciation_posted', true);
        $this->assertEquals(1000.0, (float) $deprResponse->json('data.total_amount'));
    }

    public function test_can_dispose_fixed_asset_with_gain_or_loss(): void
    {
        $asset = FixedAsset::create([
            'organization_id' => $this->org->id,
            'asset_account_id' => $this->assetAccount->id,
            'accumulated_depreciation_account_id' => $this->accumDeprAccount->id,
            'depreciation_expense_account_id' => $this->deprExpenseAccount->id,
            'asset_number' => 'FA-DISP-001',
            'name' => 'Forklift Truck',
            'purchase_date' => '2024-07-01',
            'purchase_cost' => 50000.00,
            'salvage_value' => 5000.00,
            'useful_life_months' => 45,
            'monthly_depreciation' => 1000.00,
            'status' => 'active',
        ]);

        // Dispose: cost 50000, accumulated depreciation 20000 (NBV 30000), proceeds 35000 -> Gain of 5000
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson("/api/v1/organizations/{$this->org->id}/fixed-assets/{$asset->id}/dispose", [
                'disposal_date' => '2025-07-25',
                'accounting_period_id' => $this->period->id,
                'proceeds' => 35000.00,
                'proceeds_account_id' => $this->cashAccount->id,
                'gain_loss_account_id' => $this->gainLossAccount->id,
                'accumulated_depreciation' => 20000.00,
            ]);

        $response->assertOk()
            ->assertJsonPath('data.disposed', true);
        $this->assertEquals(30000.0, (float) $response->json('data.net_book_value'));
        $this->assertEquals(5000.0, (float) $response->json('data.gain_or_loss'));

        $this->assertDatabaseHas('fixed_assets', [
            'id' => $asset->id,
            'status' => 'disposed',
            'disposal_proceeds' => 35000.00,
            'gain_loss_amount' => 5000.00,
        ]);
    }

    public function test_can_impair_fixed_asset_per_ias36(): void
    {
        $asset = FixedAsset::create([
            'organization_id' => $this->org->id,
            'asset_account_id' => $this->assetAccount->id,
            'accumulated_depreciation_account_id' => $this->accumDeprAccount->id,
            'depreciation_expense_account_id' => $this->deprExpenseAccount->id,
            'asset_number' => 'FA-IMP-001',
            'name' => 'Obsolete CNC Milling Unit',
            'purchase_date' => '2023-01-01',
            'purchase_cost' => 80000.00,
            'salvage_value' => 0.00,
            'useful_life_months' => 40,
            'monthly_depreciation' => 2000.00,
            'status' => 'active',
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson("/api/v1/organizations/{$this->org->id}/fixed-assets/{$asset->id}/impair", [
                'impairment_loss' => 15000.00,
                'impairment_loss_account_id' => $this->deprExpenseAccount->id,
                'accounting_period_id' => $this->period->id,
                'impairment_date' => '2025-07-28',
                'reason' => 'Technological obsolescence due to newer machinery',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.impaired', true);
        $this->assertEquals(15000.0, (float) $response->json('data.impairment_loss'));

        $this->assertDatabaseHas('fixed_assets', [
            'id' => $asset->id,
            'status' => 'impaired',
            'impairment_loss' => 15000.00,
        ]);
    }
}
