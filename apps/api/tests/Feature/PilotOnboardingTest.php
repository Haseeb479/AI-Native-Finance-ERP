<?php

namespace Tests\Feature;

use App\Domain\Accounting\ChartOfAccounts\Models\Account;
use App\Domain\Organization\Models\Organization;
use App\Domain\Revenue\Models\RevenueContract;
use App\Domain\Sales\Models\Customer;
use App\Domain\Purchasing\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PilotOnboardingTest extends TestCase
{
    use RefreshDatabase;

    public function test_pilot_onboarding_command_executes_successfully(): void
    {
        $this->artisan('erp:pilot-onboard', [
            '--org' => 'Indus Logistics & Distribution (Pvt) Ltd',
            '--city' => 'Lahore',
            '--ntn' => '7192834-1',
            '--strn' => '3277876999999',
        ])->assertExitCode(0);

        // 1. Verify Organization was created
        $org = Organization::where('name', 'Indus Logistics & Distribution (Pvt) Ltd')->first();
        $this->assertNotNull($org);
        $this->assertEquals('PKR', $org->base_currency);
        $this->assertEquals(7, $org->fiscal_year_start_month);

        // 2. Verify Pakistan SME Chart of Accounts seeded
        $accountsCount = Account::withoutGlobalScopes()->where('organization_id', $org->id)->count();
        $this->assertGreaterThan(20, $accountsCount);

        // 3. Verify Users attached
        $this->assertDatabaseHas('users', ['email' => 'ceo@apextrading.pk']);
        $this->assertDatabaseHas('users', ['email' => 'cfo@apextrading.pk']);

        // 4. Verify Customers & Vendors created
        $customersCount = Customer::withoutGlobalScopes()->where('organization_id', $org->id)->count();
        $this->assertGreaterThanOrEqual(3, $customersCount);

        $vendorsCount = Vendor::withoutGlobalScopes()->where('organization_id', $org->id)->count();
        $this->assertGreaterThanOrEqual(2, $vendorsCount);

        // 5. Verify ASC 606 Revenue Contract created and Q1 schedules posted
        $contract = RevenueContract::withoutGlobalScopes()->where('organization_id', $org->id)->first();
        $this->assertNotNull($contract);
        $this->assertEquals(2400000.00, (float) $contract->total_contract_value);
        $this->assertEquals(12, $contract->schedules()->count());

        $postedSchedules = $contract->schedules()->where('status', 'posted')->count();
        $this->assertEquals(3, $postedSchedules);
        $this->assertEquals(600000.00, $contract->totalRecognized());
    }
}
