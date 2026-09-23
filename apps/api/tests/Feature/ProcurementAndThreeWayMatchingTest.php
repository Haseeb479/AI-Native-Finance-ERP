<?php

namespace Tests\Feature;

use App\Domain\Accounting\ChartOfAccounts\Templates\PakistanSmeChartTemplate;
use App\Domain\Accounting\Period\Services\PeriodManager;
use App\Domain\Organization\Models\Organization;
use App\Domain\Procurement\Models\GoodsReceipt;
use App\Domain\Procurement\Models\PurchaseOrder;
use App\Domain\Procurement\Models\PurchaseRequisition;
use App\Domain\Procurement\Models\ThreeWayMatch;
use App\Domain\Procurement\Services\ProcurementService;
use App\Domain\Procurement\Services\ThreeWayMatchingService;
use App\Domain\Purchasing\Models\PurchaseBill;
use App\Domain\Purchasing\Models\Vendor;
use App\Domain\Purchasing\Services\BillService;
use App\Models\User;
use Database\Seeders\AccountTypeSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProcurementAndThreeWayMatchingTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Organization $org;
    private string $ownerToken;
    private Vendor $vendor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);
        $this->seed(AccountTypeSeeder::class);

        $this->owner = User::factory()->create([
            'name' => 'Procurement Head Asad',
            'email' => 'procurement@acme.pk',
        ]);
        $this->ownerToken = $this->owner->createToken('owner')->plainTextToken;

        $this->org = Organization::create([
            'name' => 'Apex Manufacturing PK',
            'legal_name' => 'Apex Manufacturing Private Limited',
            'base_currency' => 'PKR',
            'fiscal_year_start_month' => 7,
        ]);
        $this->org->users()->attach($this->owner->id, ['role' => 'owner', 'is_default' => true]);

        PakistanSmeChartTemplate::seedForOrganization($this->org);
        app(PeriodManager::class)->generateFiscalYear($this->org, 2025);

        $this->vendor = Vendor::create([
            'organization_id' => $this->org->id,
            'name' => 'Steel Corp Pakistan',
            'email' => 'sales@steelcorp.pk',
            'currency' => 'PKR',
            'payment_terms_days' => 30,
        ]);
    }

    public function test_can_create_and_approve_purchase_requisition_and_convert_to_po(): void
    {
        // 1. Create Requisition via API
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->ownerToken}",
            'X-Organization-Id' => $this->org->id,
        ])->postJson("/api/v1/organizations/{$this->org->id}/procurement/requisitions", [
            'requested_date' => '2025-08-10',
            'purpose' => 'Quarterly raw material requirements',
            'lines' => [
                [
                    'description' => 'Industrial Steel Sheet 2mm',
                    'quantity' => 50,
                    'estimated_unit_price' => 1200.00,
                    'item_code' => 'STL-2MM',
                ],
                [
                    'description' => 'Alloy Bolts M10',
                    'quantity' => 200,
                    'estimated_unit_price' => 50.00,
                    'item_code' => 'BLT-M10',
                ],
            ],
        ]);

        $response->assertStatus(201);
        $reqId = $response->json('data.id');
        $this->assertEquals(70000.00, (float) $response->json('data.estimated_total'));

        // 2. Approve Requisition
        $requisition = PurchaseRequisition::findOrFail($reqId);
        $requisition->update(['status' => 'pending_approval']);

        $approveRes = $this->withHeaders([
            'Authorization' => "Bearer {$this->ownerToken}",
            'X-Organization-Id' => $this->org->id,
        ])->postJson("/api/v1/organizations/{$this->org->id}/procurement/requisitions/{$reqId}/approve");

        $approveRes->assertStatus(200);
        $this->assertEquals('approved', $approveRes->json('data.status'));

        // 3. Convert Requisition to Purchase Order
        $convertRes = $this->withHeaders([
            'Authorization' => "Bearer {$this->ownerToken}",
            'X-Organization-Id' => $this->org->id,
        ])->postJson("/api/v1/organizations/{$this->org->id}/procurement/requisitions/{$reqId}/convert", [
            'vendor_id' => $this->vendor->id,
            'po_date' => '2025-08-12',
            'payment_terms' => 'Net 30 Days',
        ]);

        $convertRes->assertStatus(201);
        $this->assertEquals($this->vendor->id, $convertRes->json('data.vendor_id'));
        $this->assertEquals(70000.00, (float) $convertRes->json('data.subtotal'));
        $this->assertEquals('converted', $requisition->fresh()->status);
    }

    public function test_can_issue_purchase_order_and_receive_goods_with_grn(): void
    {
        $procurementService = app(ProcurementService::class);

        // 1. Create and issue PO
        $po = $procurementService->createPurchaseOrder($this->org, [
            'vendor_id' => $this->vendor->id,
            'po_date' => '2025-08-15',
            'lines' => [
                [
                    'description' => 'Heavy Bearing 6205',
                    'quantity' => 100,
                    'unit_price' => 450.00,
                ],
            ],
        ], $this->owner);

        $this->assertEquals('draft', $po->status);

        $po = $procurementService->issuePurchaseOrder($po, $this->owner);
        $this->assertEquals('issued', $po->status);

        // 2. Receive Partial Goods (40 units)
        $poLine = $po->lines->first();
        $grn1 = $procurementService->receiveGoods($po, [
            [
                'purchase_order_line_id' => $poLine->id,
                'quantity_received' => 40,
                'unit_cost' => 450.00,
            ],
        ], [
            'received_date' => '2025-08-20',
            'delivery_note_ref' => 'DN-4401',
        ], $this->owner);

        $po->refresh();
        $this->assertEquals('partially_received', $po->status);
        $this->assertEquals(40.0, (float) $poLine->fresh()->received_quantity);

        // 3. Receive Remaining Goods (60 units)
        $grn2 = $procurementService->receiveGoods($po, [
            [
                'purchase_order_line_id' => $poLine->id,
                'quantity_received' => 60,
                'unit_cost' => 450.00,
            ],
        ], [
            'received_date' => '2025-08-22',
            'delivery_note_ref' => 'DN-4422',
        ], $this->owner);

        $po->refresh();
        $this->assertEquals('received', $po->status);
        $this->assertEquals(100.0, (float) $poLine->fresh()->received_quantity);
    }

    public function test_three_way_match_perfect_match_flow(): void
    {
        $procurementService = app(ProcurementService::class);
        $matchingService = app(ThreeWayMatchingService::class);

        // Create & Issue PO for 20 units @ 1,000 = 20,000
        $po = $procurementService->createPurchaseOrder($this->org, [
            'vendor_id' => $this->vendor->id,
            'po_date' => '2025-08-10',
            'lines' => [
                [
                    'description' => 'Precision Motors',
                    'quantity' => 20,
                    'unit_price' => 1000.00,
                ],
            ],
        ], $this->owner);
        $procurementService->issuePurchaseOrder($po, $this->owner);

        // Receive physical goods (20 units)
        $poLine = $po->lines->first();
        $grn = $procurementService->receiveGoods($po, [
            [
                'purchase_order_line_id' => $poLine->id,
                'quantity_received' => 20,
                'unit_cost' => 1000.00,
            ],
        ], ['received_date' => '2025-08-12'], $this->owner);

        // Generate matching Vendor Bill
        $bill = $procurementService->generateBillFromPO($po, $this->owner);
        $this->assertEquals(20000.00, (float) $bill->total_amount);

        // Execute 3-Way Match
        $match = $matchingService->performMatch($bill, $po, $grn, 2.0, $this->owner);

        $this->assertEquals('matched', $match->status);
        $this->assertEquals('perfect_match', $match->match_outcome);
        $this->assertEquals(0.0000, (float) $match->price_variance);
        $this->assertEquals('matched', $bill->fresh()->match_status);
    }

    public function test_three_way_match_price_variance_exceeded_and_waive_flow(): void
    {
        $procurementService = app(ProcurementService::class);
        $matchingService = app(ThreeWayMatchingService::class);

        // PO for 10 units @ 500 = 5,000
        $po = $procurementService->createPurchaseOrder($this->org, [
            'vendor_id' => $this->vendor->id,
            'po_date' => '2025-08-10',
            'lines' => [
                [
                    'description' => 'Hydraulic Valves',
                    'quantity' => 10,
                    'unit_price' => 500.00,
                ],
            ],
        ], $this->owner);
        $procurementService->issuePurchaseOrder($po, $this->owner);

        // GRN for 10 units
        $poLine = $po->lines->first();
        $grn = $procurementService->receiveGoods($po, [
            [
                'purchase_order_line_id' => $poLine->id,
                'quantity_received' => 10,
                'unit_cost' => 500.00,
            ],
        ], ['received_date' => '2025-08-11'], $this->owner);

        // Vendor sends inflated Bill @ 600 per unit instead of 500 (+20% variance)
        $expenseAccount = \App\Domain\Accounting\ChartOfAccounts\Models\Account::withoutGlobalScopes()
            ->where('organization_id', $this->org->id)
            ->where('code', '5010')
            ->first();

        $bill = app(BillService::class)->createBill($this->org, [
            'vendor_id' => $this->vendor->id,
            'purchase_order_id' => $po->id,
            'bill_date' => '2025-08-15',
            'vendor_invoice_ref' => 'OVERBILL-01',
            'lines' => [
                [
                    'expense_account_id' => $expenseAccount->id,
                    'purchase_order_line_id' => $poLine->id,
                    'description' => 'Hydraulic Valves',
                    'quantity' => 10,
                    'unit_price' => 600.00, // 20% higher than PO price of 500
                ],
            ],
        ], $this->owner);

        // Perform 3-Way Match with standard 2% tolerance
        $match = $matchingService->performMatch($bill, $po, $grn, 2.0, $this->owner);

        $this->assertEquals('exception', $match->status);
        $this->assertEquals('price_variance_exceeded', $match->match_outcome);
        $this->assertEquals(1000.00, (float) $match->price_variance); // 6000 vs 5000
        $this->assertEquals(20.00, (float) $match->price_variance_percentage);
        $this->assertEquals('exception', $bill->fresh()->match_status);

        // Waive Exception with Authorized CFO Approval
        $waivedMatch = $matchingService->waiveMatchException(
            $match,
            $this->owner,
            'Price variance approved by CFO due to market steel price surge.'
        );

        $this->assertEquals('waived', $waivedMatch->status);
        $this->assertEquals('waived', $bill->fresh()->match_status);
        $this->assertEquals($this->owner->id, $waivedMatch->waived_by);
    }

    public function test_three_way_match_quantity_variance_and_unreceived_bill_exception(): void
    {
        $procurementService = app(ProcurementService::class);
        $matchingService = app(ThreeWayMatchingService::class);

        // PO for 50 units
        $po = $procurementService->createPurchaseOrder($this->org, [
            'vendor_id' => $this->vendor->id,
            'po_date' => '2025-08-10',
            'lines' => [
                [
                    'description' => 'Brass Connectors',
                    'quantity' => 50,
                    'unit_price' => 100.00,
                ],
            ],
        ], $this->owner);
        $procurementService->issuePurchaseOrder($po, $this->owner);

        $poLine = $po->lines->first();
        $expenseAccount = \App\Domain\Accounting\ChartOfAccounts\Models\Account::withoutGlobalScopes()
            ->where('organization_id', $this->org->id)
            ->where('code', '5010')
            ->first();

        // Scenario A: Bill arrived BEFORE any goods received (Unreceived goods exception)
        $earlyBill = app(BillService::class)->createBill($this->org, [
            'vendor_id' => $this->vendor->id,
            'purchase_order_id' => $po->id,
            'bill_date' => '2025-08-11',
            'vendor_invoice_ref' => 'EARLY-BILL-01',
            'lines' => [
                [
                    'expense_account_id' => $expenseAccount->id,
                    'purchase_order_line_id' => $poLine->id,
                    'description' => 'Brass Connectors',
                    'quantity' => 50,
                    'unit_price' => 100.00,
                ],
            ],
        ], $this->owner);

        $matchA = $matchingService->performMatch($earlyBill, $po, null, 2.0, $this->owner);
        $this->assertEquals('exception', $matchA->status);
        $this->assertEquals('unreceived_bill', $matchA->match_outcome);

        // Scenario B: Physical GRN receives 30 units, but Bill charges for 50 units (Quantity variance)
        $grn = $procurementService->receiveGoods($po, [
            [
                'purchase_order_line_id' => $poLine->id,
                'quantity_received' => 30,
                'unit_cost' => 100.00,
            ],
        ], ['received_date' => '2025-08-14'], $this->owner);

        $matchB = $matchingService->performMatch($earlyBill, $po, $grn, 2.0, $this->owner);
        $this->assertEquals('exception', $matchB->status);
        $this->assertEquals('quantity_variance_exceeded', $matchB->match_outcome);
        $this->assertEquals(20.0, (float) $matchB->quantity_variance);
    }
}
