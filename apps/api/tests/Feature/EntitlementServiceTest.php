<?php

namespace Tests\Feature;

use App\Domain\Billing\Services\EntitlementService;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EntitlementServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_organization_plan_resolution_and_usage_metering(): void
    {
        $org = Organization::create([
            'name' => 'Entitlement Test Corp',
            'legal_name' => 'Entitlement Test Corp (Pvt) Ltd',
            'base_currency' => 'PKR',
            'fiscal_year_start_month' => 1,
        ]);

        $service = app(EntitlementService::class);

        // 1. Plan default resolution
        $plan = $service->getOrganizationPlan($org);
        $this->assertEquals('starter', $plan->id);
        $this->assertEquals(500, $plan->monthly_transaction_limit);

        // 2. Record usage
        $usage = $service->recordUsage($org, 'transactions', 5);
        $this->assertEquals(5, $usage);
        $this->assertEquals(5, $service->getUsage($org, 'transactions'));

        // 3. Increment again
        $service->recordUsage($org, 'transactions', 10);
        $this->assertEquals(15, $service->getUsage($org, 'transactions'));

        // 4. Assert entitled passes when below limit
        $service->assertEntitled($org, 'transactions', 100);
        $this->assertTrue(true);
    }

    public function test_exceeding_plan_limits_throws_authorization_exception(): void
    {
        $org = Organization::create([
            'name' => 'Limit Exceeded Corp',
            'legal_name' => 'Limit Exceeded Corp (Pvt) Ltd',
            'base_currency' => 'PKR',
            'fiscal_year_start_month' => 1,
        ]);

        $service = app(EntitlementService::class);

        // Starter plan limit is 500 transactions
        $service->recordUsage($org, 'transactions', 499);

        // Requesting 2 more will exceed 500
        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('Plan limit exceeded');

        $service->assertEntitled($org, 'transactions', 2);
    }

    public function test_billing_endpoint_returns_plan_and_usage_metrics(): void
    {
        $org = Organization::create([
            'name' => 'Billing API Corp',
            'legal_name' => 'Billing API Corp (Pvt) Ltd',
            'base_currency' => 'PKR',
            'fiscal_year_start_month' => 1,
        ]);

        $user = User::factory()->create();
        $user->organizations()->attach($org->id, ['role' => 'owner', 'is_default' => true]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/organizations/{$org->id}/billing");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'plan' => ['id', 'name', 'price_monthly', 'currency'],
                    'entitlements' => ['monthly_transaction_limit', 'monthly_ai_query_limit'],
                    'current_usage' => ['billing_period', 'transactions', 'ai_queries'],
                ],
            ]);
    }
}
