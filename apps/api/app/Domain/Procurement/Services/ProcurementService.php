<?php

namespace App\Domain\Procurement\Services;

use App\Domain\Inventory\Models\Product;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\Services\InventoryService;
use App\Domain\Organization\Models\Organization;
use App\Domain\Procurement\Models\GoodsReceipt;
use App\Domain\Procurement\Models\GoodsReceiptLine;
use App\Domain\Procurement\Models\PurchaseOrder;
use App\Domain\Procurement\Models\PurchaseOrderLine;
use App\Domain\Procurement\Models\PurchaseRequisition;
use App\Domain\Procurement\Models\PurchaseRequisitionLine;
use App\Domain\Purchasing\Models\PurchaseBill;
use App\Domain\Purchasing\Models\PurchaseBillLine;
use App\Domain\Purchasing\Models\Vendor;
use App\Domain\Purchasing\Services\BillService;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ProcurementService
{
    public function __construct(
        protected ?BillService $billService = null,
        protected ?InventoryService $inventoryService = null
    ) {
        $this->billService = $billService ?? app(BillService::class);
        $this->inventoryService = $inventoryService ?? app(InventoryService::class);
    }

    /**
     * Create a purchase requisition.
     */
    public function createRequisition(Organization $organization, array $data, User $user): PurchaseRequisition
    {
        $requestedDate = ! empty($data['requested_date']) ? Carbon::parse($data['requested_date']) : Carbon::today();
        $requiredByDate = ! empty($data['required_by_date']) ? Carbon::parse($data['required_by_date']) : null;
        $reqNumber = $data['requisition_number'] ?? $this->generateRequisitionNumber($organization, $requestedDate);

        return DB::transaction(function () use ($organization, $data, $user, $requestedDate, $requiredByDate, $reqNumber) {
            $estimatedTotal = 0.0;
            $lineItems = [];
            $lineNumber = 1;

            foreach ($data['lines'] as $line) {
                $qty = (float) ($line['quantity'] ?? 1.0);
                $unitPrice = (float) ($line['estimated_unit_price'] ?? 0.0);
                $subtotal = round($qty * $unitPrice, 4);
                $estimatedTotal += $subtotal;

                $lineItems[] = [
                    'organization_id' => $organization->id,
                    'line_number' => $lineNumber++,
                    'item_code' => $line['item_code'] ?? null,
                    'description' => $line['description'],
                    'quantity' => $qty,
                    'estimated_unit_price' => $unitPrice,
                    'estimated_subtotal' => $subtotal,
                ];
            }

            $requisition = PurchaseRequisition::withoutGlobalScopes()->create([
                'organization_id' => $organization->id,
                'requisition_number' => $reqNumber,
                'status' => 'draft',
                'requested_date' => $requestedDate->toDateString(),
                'required_by_date' => $requiredByDate ? $requiredByDate->toDateString() : null,
                'purpose' => $data['purpose'] ?? null,
                'estimated_total' => $estimatedTotal,
                'requested_by' => $user->id,
            ]);

            foreach ($lineItems as $item) {
                $item['purchase_requisition_id'] = $requisition->id;
                PurchaseRequisitionLine::withoutGlobalScopes()->create($item);
            }

            return $requisition->load('lines');
        });
    }

    /**
     * Submit purchase requisition for approval.
     */
    public function submitRequisition(PurchaseRequisition $requisition, User $user): PurchaseRequisition
    {
        if (! in_array($requisition->status, ['draft', 'rejected'])) {
            throw new InvalidArgumentException("Only draft or rejected requisitions can be submitted.");
        }

        $requisition->update([
            'status' => 'pending_approval',
            'rejection_reason' => null,
        ]);

        return $requisition->fresh();
    }

    /**
     * Approve purchase requisition.
     */
    public function approveRequisition(PurchaseRequisition $requisition, User $user): PurchaseRequisition
    {
        if ($requisition->status !== 'pending_approval') {
            throw new InvalidArgumentException("Only requisitions pending approval can be approved.");
        }

        $requisition->update([
            'status' => 'approved',
            'approved_by' => $user->id,
            'approved_at' => now(),
        ]);

        return $requisition->fresh();
    }

    /**
     * Reject purchase requisition.
     */
    public function rejectRequisition(PurchaseRequisition $requisition, User $user, string $reason): PurchaseRequisition
    {
        if ($requisition->status !== 'pending_approval') {
            throw new InvalidArgumentException("Only requisitions pending approval can be rejected.");
        }

        $requisition->update([
            'status' => 'rejected',
            'rejected_by' => $user->id,
            'rejected_at' => now(),
            'rejection_reason' => $reason,
        ]);

        return $requisition->fresh();
    }

    /**
     * Convert approved requisition to a Purchase Order.
     */
    public function convertRequisitionToPO(PurchaseRequisition $requisition, string $vendorId, User $user, array $additionalData = []): PurchaseOrder
    {
        if ($requisition->status !== 'approved') {
            throw new InvalidArgumentException("Only approved requisitions can be converted into a Purchase Order.");
        }

        $organization = Organization::findOrFail($requisition->organization_id);
        $vendor = Vendor::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->findOrFail($vendorId);

        return DB::transaction(function () use ($organization, $requisition, $vendor, $user, $additionalData) {
            $poDate = ! empty($additionalData['po_date']) ? Carbon::parse($additionalData['po_date']) : Carbon::today();
            $poNumber = $additionalData['po_number'] ?? $this->generatePoNumber($organization, $poDate);

            $lines = [];
            foreach ($requisition->lines as $reqLine) {
                // If item_code matches an existing product SKU, link product_id
                $product = Product::withoutGlobalScopes()
                    ->where('organization_id', $organization->id)
                    ->where('sku', $reqLine->item_code)
                    ->first();

                $lines[] = [
                    'product_id' => $product?->id,
                    'description' => $reqLine->description,
                    'quantity' => (float) $reqLine->quantity,
                    'unit_price' => (float) $reqLine->estimated_unit_price,
                ];
            }

            $poData = array_merge([
                'vendor_id' => $vendor->id,
                'purchase_requisition_id' => $requisition->id,
                'po_number' => $poNumber,
                'po_date' => $poDate->toDateString(),
                'expected_delivery_date' => $requisition->required_by_date ? $requisition->required_by_date->toDateString() : null,
                'currency' => $vendor->currency ?? $organization->base_currency ?? 'PKR',
                'lines' => $lines,
            ], $additionalData);

            $po = $this->createPurchaseOrder($organization, $poData, $user);

            $requisition->update(['status' => 'converted']);

            return $po;
        });
    }

    /**
     * Create a purchase order.
     */
    public function createPurchaseOrder(Organization $organization, array $data, User $user): PurchaseOrder
    {
        $vendor = Vendor::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->findOrFail($data['vendor_id']);

        $poDate = ! empty($data['po_date']) ? Carbon::parse($data['po_date']) : Carbon::today();
        $deliveryDate = ! empty($data['expected_delivery_date']) ? Carbon::parse($data['expected_delivery_date']) : null;
        $poNumber = $data['po_number'] ?? $this->generatePoNumber($organization, $poDate);

        return DB::transaction(function () use ($organization, $vendor, $data, $user, $poDate, $deliveryDate, $poNumber) {
            $subtotal = 0.0;
            $poLines = [];
            $lineNumber = 1;

            foreach ($data['lines'] as $line) {
                $qty = (float) ($line['quantity'] ?? 1.0);
                $unitPrice = (float) ($line['unit_price'] ?? 0.0);
                $lineSubtotal = round($qty * $unitPrice, 4);
                $subtotal += $lineSubtotal;

                $poLines[] = [
                    'organization_id' => $organization->id,
                    'line_number' => $lineNumber++,
                    'product_id' => $line['product_id'] ?? null,
                    'description' => $line['description'],
                    'quantity' => $qty,
                    'unit_price' => $unitPrice,
                    'subtotal' => $lineSubtotal,
                    'received_quantity' => 0.0000,
                    'billed_quantity' => 0.0000,
                ];
            }

            $taxAmount = (float) ($data['tax_amount'] ?? 0.0);
            $totalAmount = round($subtotal + $taxAmount, 4);

            $po = PurchaseOrder::withoutGlobalScopes()->create([
                'organization_id' => $organization->id,
                'vendor_id' => $vendor->id,
                'purchase_requisition_id' => $data['purchase_requisition_id'] ?? null,
                'po_number' => $poNumber,
                'po_date' => $poDate->toDateString(),
                'expected_delivery_date' => $deliveryDate ? $deliveryDate->toDateString() : null,
                'status' => 'draft',
                'currency' => $data['currency'] ?? $organization->base_currency ?? 'PKR',
                'exchange_rate' => $data['exchange_rate'] ?? 1.000000,
                'subtotal' => $subtotal,
                'tax_amount' => $taxAmount,
                'total_amount' => $totalAmount,
                'payment_terms' => $data['payment_terms'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $user->id,
            ]);

            foreach ($poLines as $lineAttrs) {
                $lineAttrs['purchase_order_id'] = $po->id;
                PurchaseOrderLine::withoutGlobalScopes()->create($lineAttrs);
            }

            return $po->load(['vendor', 'lines']);
        });
    }

    /**
     * Issue purchase order to vendor.
     */
    public function issuePurchaseOrder(PurchaseOrder $order, User $user): PurchaseOrder
    {
        if ($order->status !== 'draft') {
            throw new InvalidArgumentException("Only draft purchase orders can be issued.");
        }

        $order->update([
            'status' => 'issued',
            'issued_at' => now(),
        ]);

        return $order->fresh(['vendor', 'lines']);
    }

    /**
     * Receive goods against a purchase order (creates Goods Receipt Note).
     */
    public function receiveGoods(PurchaseOrder $order, array $receiptLines, array $metadata, User $user): GoodsReceipt
    {
        if (! in_array($order->status, ['issued', 'partially_received'])) {
            throw new InvalidArgumentException("Goods can only be received against issued or partially received purchase orders.");
        }

        $organization = Organization::findOrFail($order->organization_id);
        $receivedDate = ! empty($metadata['received_date']) ? Carbon::parse($metadata['received_date']) : Carbon::today();
        $grnNumber = $metadata['grn_number'] ?? $this->generateGrnNumber($organization, $receivedDate);
        $warehouseId = $metadata['warehouse_id'] ?? null;

        $warehouse = $warehouseId ? Warehouse::withoutGlobalScopes()->find($warehouseId) : null;

        return DB::transaction(function () use ($organization, $order, $receiptLines, $metadata, $user, $receivedDate, $grnNumber, $warehouse) {
            $grn = GoodsReceipt::withoutGlobalScopes()->create([
                'organization_id' => $organization->id,
                'purchase_order_id' => $order->id,
                'vendor_id' => $order->vendor_id,
                'warehouse_id' => $warehouse?->id,
                'grn_number' => $grnNumber,
                'received_date' => $receivedDate->toDateString(),
                'delivery_note_ref' => $metadata['delivery_note_ref'] ?? null,
                'status' => 'received',
                'notes' => $metadata['notes'] ?? null,
                'received_by' => $user->id,
            ]);

            foreach ($receiptLines as $lineData) {
                $poLine = PurchaseOrderLine::withoutGlobalScopes()
                    ->where('purchase_order_id', $order->id)
                    ->findOrFail($lineData['purchase_order_line_id']);

                $qtyReceived = (float) ($lineData['quantity_received'] ?? 0.0);
                $unitCost = (float) ($lineData['unit_cost'] ?? $poLine->unit_price);
                $subtotal = round($qtyReceived * $unitCost, 4);

                GoodsReceiptLine::withoutGlobalScopes()->create([
                    'organization_id' => $organization->id,
                    'goods_receipt_id' => $grn->id,
                    'purchase_order_line_id' => $poLine->id,
                    'product_id' => $poLine->product_id,
                    'quantity_received' => $qtyReceived,
                    'unit_cost' => $unitCost,
                    'subtotal' => $subtotal,
                ]);

                // Update PO line received quantity
                $poLine->increment('received_quantity', $qtyReceived);

                // If product and warehouse are present, update perpetual inventory stock
                if ($poLine->product_id && $warehouse) {
                    $product = Product::withoutGlobalScopes()->find($poLine->product_id);
                    if ($product) {
                        $this->inventoryService->recordStockReceipt(
                            $product,
                            $warehouse,
                            $qtyReceived,
                            $unitCost,
                            'goods_receipt',
                            $grn->id,
                            $user,
                            "Received on GRN {$grnNumber} for PO {$order->po_number}"
                        );
                    }
                }
            }

            // Check if PO is fully received
            $order->load('lines');
            $allReceived = true;
            $anyReceived = false;

            foreach ($order->lines as $line) {
                if ((float) $line->received_quantity >= (float) $line->quantity) {
                    $anyReceived = true;
                } else {
                    $allReceived = false;
                    if ((float) $line->received_quantity > 0) {
                        $anyReceived = true;
                    }
                }
            }

            $order->update([
                'status' => $allReceived ? 'received' : ($anyReceived ? 'partially_received' : $order->status),
            ]);

            return $grn->load(['lines.purchaseOrderLine', 'vendor']);
        });
    }

    /**
     * Generate a Purchase Bill from a Purchase Order.
     */
    public function generateBillFromPO(PurchaseOrder $order, User $user, array $overrides = []): PurchaseBill
    {
        $organization = Organization::findOrFail($order->organization_id);

        $defaultExpenseAccount = \App\Domain\Accounting\ChartOfAccounts\Models\Account::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->where(function ($q) {
                $q->where('code', '5010') // COGS / Purchases
                  ->orWhere('code', '1070') // Inventory
                  ->orWhere('classification', 'expense');
            })
            ->first();

        if (! $defaultExpenseAccount) {
            $defaultExpenseAccount = \App\Domain\Accounting\ChartOfAccounts\Models\Account::withoutGlobalScopes()
                ->where('organization_id', $organization->id)
                ->where('classification', 'expense')
                ->firstOrFail();
        }

        $lines = [];
        foreach ($order->lines as $poLine) {
            $qty = (float) ($poLine->quantity - $poLine->billed_quantity);
            if ($qty <= 0) {
                $qty = (float) $poLine->quantity;
            }

            $lines[] = [
                'expense_account_id' => $defaultExpenseAccount->id,
                'description' => $poLine->description,
                'quantity' => $qty,
                'unit_price' => (float) $poLine->unit_price,
                'purchase_order_line_id' => $poLine->id,
                'product_id' => $poLine->product_id,
            ];
        }

        $billData = array_merge([
            'vendor_id' => $order->vendor_id,
            'purchase_order_id' => $order->id,
            'bill_date' => Carbon::today()->toDateString(),
            'due_date' => Carbon::today()->addDays(30)->toDateString(),
            'vendor_invoice_ref' => $overrides['vendor_invoice_ref'] ?? "REF-{$order->po_number}",
            'currency' => $order->currency,
            'exchange_rate' => $order->exchange_rate,
            'lines' => $lines,
            'notes' => "Generated from PO {$order->po_number}",
        ], $overrides);

        $bill = $this->billService->createBill($organization, $billData, $user);

        // Update purchase_order_id explicitly on bill
        $bill->update([
            'purchase_order_id' => $order->id,
        ]);

        return $bill->fresh(['lines', 'vendor', 'purchaseOrder']);
    }

    protected function generateRequisitionNumber(Organization $organization, Carbon $date): string
    {
        $year = $date->format('Y');
        $count = PurchaseRequisition::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->whereYear('requested_date', $year)
            ->count() + 1;

        return sprintf('PR-%s-%04d', $year, $count);
    }

    protected function generatePoNumber(Organization $organization, Carbon $date): string
    {
        $year = $date->format('Y');
        $count = PurchaseOrder::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->whereYear('po_date', $year)
            ->count() + 1;

        return sprintf('PO-%s-%04d', $year, $count);
    }

    protected function generateGrnNumber(Organization $organization, Carbon $date): string
    {
        $year = $date->format('Y');
        $count = GoodsReceipt::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->whereYear('received_date', $year)
            ->count() + 1;

        return sprintf('GRN-%s-%04d', $year, $count);
    }
}
