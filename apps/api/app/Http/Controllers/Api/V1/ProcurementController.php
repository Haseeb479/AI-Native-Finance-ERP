<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Organization\Models\Organization;
use App\Domain\Procurement\Models\GoodsReceipt;
use App\Domain\Procurement\Models\PurchaseOrder;
use App\Domain\Procurement\Models\PurchaseRequisition;
use App\Domain\Procurement\Models\ThreeWayMatch;
use App\Domain\Procurement\Services\ProcurementService;
use App\Domain\Procurement\Services\ThreeWayMatchingService;
use App\Domain\Purchasing\Models\PurchaseBill;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProcurementController extends Controller
{
    public function __construct(
        protected ProcurementService $procurementService,
        protected ThreeWayMatchingService $threeWayMatchingService
    ) {}

    /**
     * List purchase requisitions.
     */
    public function indexRequisitions(Request $request, string $orgId): JsonResponse
    {
        $query = PurchaseRequisition::withoutGlobalScopes()
            ->where('organization_id', $orgId)
            ->with(['requester', 'lines'])
            ->latest('requested_date');

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        $requisitions = $query->paginate(20);

        return response()->json([
            'data' => $requisitions->items(),
            'meta' => [
                'current_page' => $requisitions->currentPage(),
                'total' => $requisitions->total(),
                'per_page' => $requisitions->perPage(),
            ],
            'errors' => [],
        ]);
    }

    /**
     * Create purchase requisition.
     */
    public function storeRequisition(Request $request, string $orgId): JsonResponse
    {
        $organization = Organization::findOrFail($orgId);

        $validated = $request->validate([
            'requested_date' => ['nullable', 'date'],
            'required_by_date' => ['nullable', 'date'],
            'purpose' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.description' => ['required', 'string'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0.0001'],
            'lines.*.estimated_unit_price' => ['required', 'numeric', 'min:0'],
            'lines.*.item_code' => ['nullable', 'string'],
        ]);

        $requisition = $this->procurementService->createRequisition($organization, $validated, $request->user());

        return response()->json([
            'data' => $requisition,
            'meta' => ['message' => 'Purchase requisition created successfully.'],
            'errors' => [],
        ], 201);
    }

    /**
     * Approve purchase requisition.
     */
    public function approveRequisition(Request $request, string $orgId, string $id): JsonResponse
    {
        $requisition = PurchaseRequisition::withoutGlobalScopes()
            ->where('organization_id', $orgId)
            ->findOrFail($id);

        $updated = $this->procurementService->approveRequisition($requisition, $request->user());

        return response()->json([
            'data' => $updated,
            'meta' => ['message' => 'Requisition approved successfully.'],
            'errors' => [],
        ]);
    }

    /**
     * Convert requisition to Purchase Order.
     */
    public function convertRequisitionToPo(Request $request, string $orgId, string $id): JsonResponse
    {
        $requisition = PurchaseRequisition::withoutGlobalScopes()
            ->where('organization_id', $orgId)
            ->findOrFail($id);

        $validated = $request->validate([
            'vendor_id' => ['required', 'uuid'],
            'po_date' => ['nullable', 'date'],
            'expected_delivery_date' => ['nullable', 'date'],
            'payment_terms' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
        ]);

        $po = $this->procurementService->convertRequisitionToPO(
            $requisition,
            $validated['vendor_id'],
            $request->user(),
            $validated
        );

        return response()->json([
            'data' => $po,
            'meta' => ['message' => 'Purchase Order generated from requisition.'],
            'errors' => [],
        ], 201);
    }

    /**
     * List Purchase Orders.
     */
    public function indexOrders(Request $request, string $orgId): JsonResponse
    {
        $query = PurchaseOrder::withoutGlobalScopes()
            ->where('organization_id', $orgId)
            ->with(['vendor', 'lines'])
            ->latest('po_date');

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        $orders = $query->paginate(20);

        return response()->json([
            'data' => $orders->items(),
            'meta' => [
                'current_page' => $orders->currentPage(),
                'total' => $orders->total(),
                'per_page' => $orders->perPage(),
            ],
            'errors' => [],
        ]);
    }

    /**
     * Create direct Purchase Order.
     */
    public function storeOrder(Request $request, string $orgId): JsonResponse
    {
        $organization = Organization::findOrFail($orgId);

        $validated = $request->validate([
            'vendor_id' => ['required', 'uuid'],
            'po_date' => ['nullable', 'date'],
            'expected_delivery_date' => ['nullable', 'date'],
            'currency' => ['nullable', 'string', 'size:3'],
            'tax_amount' => ['nullable', 'numeric'],
            'payment_terms' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.description' => ['required', 'string'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0.0001'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
            'lines.*.product_id' => ['nullable', 'uuid'],
        ]);

        $order = $this->procurementService->createPurchaseOrder($organization, $validated, $request->user());

        return response()->json([
            'data' => $order,
            'meta' => ['message' => 'Purchase order created successfully.'],
            'errors' => [],
        ], 201);
    }

    /**
     * Issue purchase order.
     */
    public function issueOrder(Request $request, string $orgId, string $id): JsonResponse
    {
        $order = PurchaseOrder::withoutGlobalScopes()
            ->where('organization_id', $orgId)
            ->findOrFail($id);

        $updated = $this->procurementService->issuePurchaseOrder($order, $request->user());

        return response()->json([
            'data' => $updated,
            'meta' => ['message' => 'Purchase order issued.'],
            'errors' => [],
        ]);
    }

    /**
     * Receive goods against Purchase Order (creates GRN).
     */
    public function receiveGoods(Request $request, string $orgId, string $id): JsonResponse
    {
        $order = PurchaseOrder::withoutGlobalScopes()
            ->where('organization_id', $orgId)
            ->findOrFail($id);

        $validated = $request->validate([
            'received_date' => ['nullable', 'date'],
            'delivery_note_ref' => ['nullable', 'string'],
            'warehouse_id' => ['nullable', 'uuid'],
            'notes' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.purchase_order_line_id' => ['required', 'uuid'],
            'lines.*.quantity_received' => ['required', 'numeric', 'min:0.0001'],
            'lines.*.unit_cost' => ['nullable', 'numeric'],
        ]);

        $grn = $this->procurementService->receiveGoods(
            $order,
            $validated['lines'],
            $validated,
            $request->user()
        );

        return response()->json([
            'data' => $grn,
            'meta' => ['message' => 'Goods Receipt Note created successfully.'],
            'errors' => [],
        ], 201);
    }

    /**
     * Generate Purchase Bill from PO.
     */
    public function generateBill(Request $request, string $orgId, string $id): JsonResponse
    {
        $order = PurchaseOrder::withoutGlobalScopes()
            ->where('organization_id', $orgId)
            ->findOrFail($id);

        $bill = $this->procurementService->generateBillFromPO($order, $request->user(), $request->all());

        return response()->json([
            'data' => $bill,
            'meta' => ['message' => 'Purchase Bill generated from PO.'],
            'errors' => [],
        ], 201);
    }

    /**
     * Execute Automated 3-Way Match.
     */
    public function matchThreeWay(Request $request, string $orgId): JsonResponse
    {
        $validated = $request->validate([
            'purchase_bill_id' => ['required', 'uuid'],
            'purchase_order_id' => ['nullable', 'uuid'],
            'goods_receipt_id' => ['nullable', 'uuid'],
            'tolerance_percentage' => ['nullable', 'numeric', 'min:0'],
        ]);

        $bill = PurchaseBill::withoutGlobalScopes()
            ->where('organization_id', $orgId)
            ->findOrFail($validated['purchase_bill_id']);

        $po = ! empty($validated['purchase_order_id'])
            ? PurchaseOrder::withoutGlobalScopes()->where('organization_id', $orgId)->find($validated['purchase_order_id'])
            : null;

        $grn = ! empty($validated['goods_receipt_id'])
            ? GoodsReceipt::withoutGlobalScopes()->where('organization_id', $orgId)->find($validated['goods_receipt_id'])
            : null;

        $tolerance = (float) ($validated['tolerance_percentage'] ?? 2.0);

        $match = $this->threeWayMatchingService->performMatch($bill, $po, $grn, $tolerance, $request->user());

        return response()->json([
            'data' => $match,
            'meta' => [
                'message' => $match->status === 'matched'
                    ? '3-Way Match verified successfully.'
                    : '3-Way Match exception detected.',
            ],
            'errors' => [],
        ]);
    }

    /**
     * Waive 3-Way Match exception.
     */
    public function waiveMatch(Request $request, string $orgId, string $id): JsonResponse
    {
        $match = ThreeWayMatch::withoutGlobalScopes()
            ->where('organization_id', $orgId)
            ->findOrFail($id);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3'],
        ]);

        $updated = $this->threeWayMatchingService->waiveMatchException($match, $request->user(), $validated['reason']);

        return response()->json([
            'data' => $updated,
            'meta' => ['message' => 'Match exception waived successfully.'],
            'errors' => [],
        ]);
    }

    /**
     * List 3-Way matches.
     */
    public function indexMatches(Request $request, string $orgId): JsonResponse
    {
        $query = ThreeWayMatch::withoutGlobalScopes()
            ->where('organization_id', $orgId)
            ->with(['purchaseBill', 'purchaseOrder', 'goodsReceipt'])
            ->latest();

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        $matches = $query->paginate(20);

        return response()->json([
            'data' => $matches->items(),
            'meta' => [
                'current_page' => $matches->currentPage(),
                'total' => $matches->total(),
            ],
            'errors' => [],
        ]);
    }
}
