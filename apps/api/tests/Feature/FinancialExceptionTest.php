<?php

namespace Tests\Feature;

use App\Domain\Exceptions\Services\FinancialExceptionService;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class FinancialExceptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_raise_and_resolve_financial_exception(): void
    {
        $org = Organization::create([
            'name' => 'Exception Org Corp',
            'legal_name' => 'Exception Org Corp (Pvt) Ltd',
            'base_currency' => 'PKR',
            'fiscal_year_start_month' => 1,
        ]);

        $user = User::factory()->create();
        $user->organizations()->attach($org->id, ['role' => 'owner', 'is_default' => true]);

        $service = app(FinancialExceptionService::class);

        // 1. Raise exception
        $exception = $service->raiseException(
            organization: $org,
            type: 'unmatched_bank_transaction',
            title: 'Unmatched deposit of Rs. 450,000 on HBL account',
            description: 'Statement line ref #DEP-88392 does not correlate to any posted invoice.',
            payload: ['amount' => 450000, 'bank' => 'HBL'],
            severity: 'high',
            aggregateType: 'bank_transaction',
            aggregateId: 'tx-88392'
        );

        $this->assertEquals('open', $exception->status);
        $this->assertEquals('high', $exception->severity);

        // 2. Fetch open exceptions
        $open = $service->getOpenExceptions($org);
        $this->assertCount(1, $open);

        // 3. Resolve exception
        $resolved = $service->resolveException($exception->id, $user, 'Matched manually against advance customer receipt AR-901.');
        $this->assertEquals('resolved', $resolved->status);
        $this->assertEquals($user->id, $resolved->resolved_by_user_id);
        $this->assertNotNull($resolved->resolved_at);

        // 4. Verify open exceptions is now 0
        $this->assertCount(0, $service->getOpenExceptions($org));
    }

    public function test_invalid_exception_type_is_rejected(): void
    {
        $org = Organization::create([
            'name' => 'Invalid Type Org',
            'legal_name' => 'Invalid Type Org (Pvt) Ltd',
            'base_currency' => 'PKR',
            'fiscal_year_start_month' => 1,
        ]);

        $service = app(FinancialExceptionService::class);

        $this->expectException(InvalidArgumentException::class);
        $service->raiseException($org, 'completely_unknown_type', 'Test Title');
    }

    public function test_exceptions_api_endpoints(): void
    {
        $org = Organization::create([
            'name' => 'Exception API Org',
            'legal_name' => 'Exception API Org (Pvt) Ltd',
            'base_currency' => 'PKR',
            'fiscal_year_start_month' => 1,
        ]);

        $user = User::factory()->create();
        $user->organizations()->attach($org->id, ['role' => 'owner', 'is_default' => true]);

        // Raise exception via API
        $createRes = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/organizations/{$org->id}/exceptions", [
                'exception_type' => 'duplicate_invoice',
                'title' => 'Potential duplicate vendor bill for ABC Logistics',
                'severity' => 'critical',
            ]);

        $createRes->assertStatus(201);
        $exceptionId = $createRes->json('data.id');

        // List exceptions via API
        $listRes = $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/organizations/{$org->id}/exceptions");

        $listRes->assertStatus(200);
        $this->assertEquals(1, $listRes->json('data.total_count'));

        // Resolve via API
        $resolveRes = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/organizations/{$org->id}/exceptions/{$exceptionId}/resolve", [
                'notes' => 'Confirmed duplicate; original bill #BILL-1002 voided.',
            ]);

        $resolveRes->assertStatus(200);
        $this->assertEquals('resolved', $resolveRes->json('data.exception.status'));
    }
}
