<?php

namespace App\Domain\Procurement\Services;

use App\Domain\Audit\Services\AuditService;
use App\Domain\Procurement\Models\GoodsReceipt;
use App\Domain\Procurement\Models\PurchaseOrder;
use App\Domain\Procurement\Models\ThreeWayMatch;
use App\Domain\Purchasing\Models\PurchaseBill;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ThreeWayMatchingService
{
    /**
     * Perform automated 3-Way Match between Purchase Bill, Purchase Order, and Goods Receipt.
     */
    public function performMatch(
        PurchaseBill $bill,
        ?PurchaseOrder $po = null,
        ?GoodsReceipt $grn = null,
        float $tolerancePercent = 2.0,
        ?User $user = null
    ): ThreeWayMatch {
        $organizationId = $bill->organization_id;

        // Resolve PO
        if (! $po && $bill->purchase_order_id) {
            $po = PurchaseOrder::withoutGlobalScopes()
                ->where('organization_id', $organizationId)
                ->find($bill->purchase_order_id);
        }

        // If no PO, flag as exception
        if (! $po) {
            return DB::transaction(function () use ($organizationId, $bill, $tolerancePercent, $user) {
                $match = ThreeWayMatch::withoutGlobalScopes()->create([
                    'organization_id' => $organizationId,
                    'purchase_bill_id' => $bill->id,
                    'purchase_order_id' => null,
                    'goods_receipt_id' => null,
                    'status' => 'exception',
                    'match_outcome' => 'unreceived_bill',
                    'tolerance_percentage' => $tolerancePercent,
                    'po_total' => 0.0000,
                    'grn_total' => 0.0000,
                    'bill_total' => (float) $bill->total_amount,
                    'price_variance' => (float) $bill->total_amount,
                    'price_variance_percentage' => 100.0000,
                    'quantity_variance' => 0.0000,
                    'discrepancies' => [
                        ['type' => 'missing_po', 'message' => "No Purchase Order linked to Bill {$bill->bill_number}."],
                    ],
                    'matched_by' => $user?->id,
                    'matched_at' => now(),
                ]);

                $bill->update(['match_status' => 'exception']);

                return $match;
            });
        }

        // Resolve GRN
        if (! $grn) {
            $grn = GoodsReceipt::withoutGlobalScopes()
                ->where('organization_id', $organizationId)
                ->where('purchase_order_id', $po->id)
                ->latest()
                ->first();
        }

        $billTotal = (float) $bill->total_amount;
        $poTotal = (float) $po->total_amount;
        
        $grnTotal = 0.0;
        if ($grn) {
            $grnTotal = (float) $grn->lines()->sum('subtotal');
        } else {
            // Check all GRNs for this PO
            $grnTotal = (float) GoodsReceipt::withoutGlobalScopes()
                ->where('organization_id', $organizationId)
                ->where('purchase_order_id', $po->id)
                ->with('lines')
                ->get()
                ->flatMap->lines
                ->sum('subtotal');
        }

        $priceVariance = round($billTotal - $poTotal, 4);
        $priceVariancePct = $poTotal > 0 ? round(($priceVariance / $poTotal) * 100, 4) : 0.0;

        $discrepancies = [];
        $totalQtyVariance = 0.0;

        // Check if goods have been received
        $hasGrn = $grn !== null || $po->goodsReceipts()->exists();
        if (! $hasGrn) {
            $discrepancies[] = [
                'type' => 'unreceived_goods',
                'message' => "No Goods Receipt Note found for PO {$po->po_number}. Goods have not been received in warehouse.",
            ];
        }

        // Line-by-line comparison
        $bill->load('lines');
        $po->load('lines');

        foreach ($bill->lines as $bLine) {
            // Find corresponding PO line
            $pLine = null;
            if ($bLine->purchase_order_line_id) {
                $pLine = $po->lines->firstWhere('id', $bLine->purchase_order_line_id);
            }
            if (! $pLine) {
                $pLine = $po->lines->firstWhere('description', $bLine->description);
            }

            if ($pLine) {
                $bQty = (float) $bLine->quantity;
                $pQty = (float) $pLine->quantity;
                $receivedQty = (float) $pLine->received_quantity;

                // Check unit price variance
                $unitDiff = round((float) $bLine->unit_price - (float) $pLine->unit_price, 4);
                if (abs($unitDiff) > 0.001) {
                    $lineVariancePct = (float) $pLine->unit_price > 0 ? round(($unitDiff / (float) $pLine->unit_price) * 100, 2) : 0.0;
                    if (abs($lineVariancePct) > $tolerancePercent) {
                        $discrepancies[] = [
                            'type' => 'unit_price_variance',
                            'line_number' => $bLine->line_number,
                            'description' => $bLine->description,
                            'bill_unit_price' => (float) $bLine->unit_price,
                            'po_unit_price' => (float) $pLine->unit_price,
                            'variance_percent' => $lineVariancePct,
                            'message' => "Line {$bLine->line_number} unit price ({$bLine->unit_price}) differs from PO ({$pLine->unit_price}) by {$lineVariancePct}%, exceeding tolerance {$tolerancePercent}%.",
                        ];
                    }
                }

                // Check quantity variance
                if ($hasGrn && $bQty > $receivedQty) {
                    $qtyDiff = round($bQty - $receivedQty, 4);
                    $totalQtyVariance += $qtyDiff;
                    $discrepancies[] = [
                        'type' => 'quantity_exceeded_receipt',
                        'line_number' => $bLine->line_number,
                        'description' => $bLine->description,
                        'billed_quantity' => $bQty,
                        'received_quantity' => $receivedQty,
                        'excess_quantity' => $qtyDiff,
                        'message' => "Line {$bLine->line_number} billed quantity ({$bQty}) exceeds physical goods received ({$receivedQty}) by {$qtyDiff}.",
                    ];
                }
            } else {
                $discrepancies[] = [
                    'type' => 'unmatched_line',
                    'line_number' => $bLine->line_number,
                    'description' => $bLine->description,
                    'message' => "Line item '{$bLine->description}' does not exist on PO {$po->po_number}.",
                ];
            }
        }

        // Determine outcome
        $isPriceWithinTolerance = abs($priceVariancePct) <= $tolerancePercent;
        $isQtyMatched = $totalQtyVariance <= 0.0001;

        if (! $hasGrn) {
            $outcome = 'unreceived_bill';
            $status = 'exception';
        } elseif (! $isQtyMatched) {
            $outcome = 'quantity_variance_exceeded';
            $status = 'exception';
        } elseif (! $isPriceWithinTolerance) {
            $outcome = 'price_variance_exceeded';
            $status = 'exception';
        } else {
            $outcome = ($priceVariance == 0.0 && empty($discrepancies)) ? 'perfect_match' : 'within_tolerance';
            $status = 'matched';
        }

        return DB::transaction(function () use (
            $organizationId,
            $bill,
            $po,
            $grn,
            $status,
            $outcome,
            $tolerancePercent,
            $poTotal,
            $grnTotal,
            $billTotal,
            $priceVariance,
            $priceVariancePct,
            $totalQtyVariance,
            $discrepancies,
            $user
        ) {
            $match = ThreeWayMatch::withoutGlobalScopes()->create([
                'organization_id' => $organizationId,
                'purchase_bill_id' => $bill->id,
                'purchase_order_id' => $po->id,
                'goods_receipt_id' => $grn?->id,
                'status' => $status,
                'match_outcome' => $outcome,
                'tolerance_percentage' => $tolerancePercent,
                'po_total' => $poTotal,
                'grn_total' => $grnTotal,
                'bill_total' => $billTotal,
                'price_variance' => $priceVariance,
                'price_variance_percentage' => $priceVariancePct,
                'quantity_variance' => $totalQtyVariance,
                'discrepancies' => $discrepancies,
                'matched_by' => $user?->id,
                'matched_at' => now(),
            ]);

            $bill->update(['match_status' => $status]);

            // If matched and bill is in pending_approval, we can log the automated verification
            if ($status === 'matched' && class_exists(AuditService::class) && $user) {
                app(AuditService::class)->log(
                    $organizationId,
                    $user,
                    'procurement:3way_matched',
                    $match,
                    [],
                    ['outcome' => $outcome, 'bill_id' => $bill->id, 'po_id' => $po->id]
                );
            }

            return $match;
        });
    }

    /**
     * Waive a 3-way matching exception (e.g. authorized manager approval with justification).
     */
    public function waiveMatchException(ThreeWayMatch $match, User $user, string $reason): ThreeWayMatch
    {
        if ($match->status !== 'exception') {
            throw new InvalidArgumentException("Only 3-way matching exceptions can be waived.");
        }

        return DB::transaction(function () use ($match, $user, $reason) {
            $match->update([
                'status' => 'waived',
                'waiver_reason' => $reason,
                'waived_by' => $user->id,
                'waived_at' => now(),
            ]);

            $match->purchaseBill->update(['match_status' => 'waived']);

            if (class_exists(AuditService::class)) {
                app(AuditService::class)->log(
                    $match->organization_id,
                    $user,
                    'procurement:3way_match_waived',
                    $match,
                    ['status' => 'exception'],
                    ['status' => 'waived', 'reason' => $reason]
                );
            }

            return $match->fresh(['purchaseBill', 'purchaseOrder', 'goodsReceipt']);
        });
    }
}
