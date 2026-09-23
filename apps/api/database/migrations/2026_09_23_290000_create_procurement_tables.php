<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Purchase Requisitions
        Schema::create('purchase_requisitions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('requisition_number', 50); // e.g. PR-2026-00001
            $table->string('status', 25)->default('draft'); // draft, pending_approval, approved, rejected, converted
            $table->date('requested_date');
            $table->date('required_by_date')->nullable();
            $table->text('purpose')->nullable();
            $table->decimal('estimated_total', 15, 4)->default(0.0000);
            
            $table->foreignId('requested_by')->constrained('users');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'requisition_number']);
            $table->index(['organization_id', 'status']);
        });

        // 2. Purchase Requisition Lines
        Schema::create('purchase_requisition_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('purchase_requisition_id')->constrained('purchase_requisitions')->cascadeOnDelete();
            $table->unsignedSmallInteger('line_number');
            $table->string('item_code', 50)->nullable();
            $table->text('description');
            $table->decimal('quantity', 12, 4)->default(1.0000);
            $table->decimal('estimated_unit_price', 15, 4)->default(0.0000);
            $table->decimal('estimated_subtotal', 15, 4)->default(0.0000);

            $table->timestamps();
            $table->index(['purchase_requisition_id']);
        });

        // 3. Purchase Orders
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('vendor_id')->constrained('vendors');
            $table->foreignUuid('purchase_requisition_id')->nullable()->constrained('purchase_requisitions')->nullOnDelete();
            $table->string('po_number', 50); // e.g. PO-2026-00001
            $table->date('po_date');
            $table->date('expected_delivery_date')->nullable();
            $table->string('status', 25)->default('draft'); // draft, issued, partially_received, received, cancelled, closed
            $table->string('currency', 3)->default('PKR');
            $table->decimal('exchange_rate', 15, 6)->default(1.000000);
            $table->decimal('subtotal', 15, 4)->default(0.0000);
            $table->decimal('tax_amount', 15, 4)->default(0.0000);
            $table->decimal('total_amount', 15, 4)->default(0.0000);
            $table->text('payment_terms')->nullable();
            $table->text('notes')->nullable();

            $table->foreignId('created_by')->constrained('users');
            $table->timestamp('issued_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'po_number']);
            $table->index(['organization_id', 'vendor_id']);
            $table->index(['organization_id', 'status']);
        });

        // 4. Purchase Order Lines
        Schema::create('purchase_order_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
            $table->unsignedSmallInteger('line_number');
            $table->uuid('product_id')->nullable();
            $table->text('description');
            $table->decimal('quantity', 12, 4)->default(1.0000);
            $table->decimal('unit_price', 15, 4)->default(0.0000);
            $table->decimal('subtotal', 15, 4)->default(0.0000);
            $table->decimal('received_quantity', 12, 4)->default(0.0000);
            $table->decimal('billed_quantity', 12, 4)->default(0.0000);

            $table->timestamps();
            $table->index(['purchase_order_id']);
        });

        // 5. Goods Receipt Notes (GRN)
        Schema::create('goods_receipts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
            $table->foreignUuid('vendor_id')->constrained('vendors');
            $table->uuid('warehouse_id')->nullable();
            $table->string('grn_number', 50); // e.g. GRN-2026-00001
            $table->date('received_date');
            $table->string('delivery_note_ref', 100)->nullable();
            $table->string('status', 25)->default('received'); // received, inspected, cancelled
            $table->text('notes')->nullable();

            $table->foreignId('received_by')->constrained('users');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'grn_number']);
            $table->index(['organization_id', 'purchase_order_id']);
        });

        // 6. Goods Receipt Lines
        Schema::create('goods_receipt_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('goods_receipt_id')->constrained('goods_receipts')->cascadeOnDelete();
            $table->foreignUuid('purchase_order_line_id')->constrained('purchase_order_lines')->cascadeOnDelete();
            $table->uuid('product_id')->nullable();
            $table->decimal('quantity_received', 12, 4)->default(0.0000);
            $table->decimal('unit_cost', 15, 4)->default(0.0000);
            $table->decimal('subtotal', 15, 4)->default(0.0000);

            $table->timestamps();
            $table->index(['goods_receipt_id']);
            $table->index(['purchase_order_line_id']);
        });

        // 7. Three-Way Matches
        Schema::create('three_way_matches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('purchase_bill_id')->constrained('purchase_bills')->cascadeOnDelete();
            $table->foreignUuid('purchase_order_id')->nullable()->constrained('purchase_orders')->nullOnDelete();
            $table->foreignUuid('goods_receipt_id')->nullable()->constrained('goods_receipts')->nullOnDelete();
            
            $table->string('status', 25)->default('matched'); // matched, exception, waived, resolved
            $table->string('match_outcome', 50); // perfect_match, within_tolerance, price_variance_exceeded, quantity_variance_exceeded, unreceived_bill
            $table->decimal('tolerance_percentage', 5, 2)->default(2.00);
            
            $table->decimal('po_total', 15, 4)->default(0.0000);
            $table->decimal('grn_total', 15, 4)->default(0.0000);
            $table->decimal('bill_total', 15, 4)->default(0.0000);
            $table->decimal('price_variance', 15, 4)->default(0.0000);
            $table->decimal('price_variance_percentage', 8, 4)->default(0.0000);
            $table->decimal('quantity_variance', 12, 4)->default(0.0000);
            
            $table->json('discrepancies')->nullable();
            $table->text('waiver_reason')->nullable();
            $table->foreignId('waived_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('waived_at')->nullable();

            $table->foreignId('matched_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('matched_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'purchase_bill_id']);
            $table->index(['organization_id', 'purchase_order_id']);
            $table->index(['organization_id', 'status']);
        });

        // 8. Add PO linkage to purchase bills
        Schema::table('purchase_bills', function (Blueprint $table) {
            $table->foreignUuid('purchase_order_id')->nullable()->after('vendor_id')->constrained('purchase_orders')->nullOnDelete();
            $table->string('match_status', 25)->default('unmatched')->after('status'); // unmatched, matched, exception, waived
        });

        Schema::table('purchase_bill_lines', function (Blueprint $table) {
            $table->foreignUuid('purchase_order_line_id')->nullable()->after('expense_account_id')->constrained('purchase_order_lines')->nullOnDelete();
            $table->uuid('product_id')->nullable()->after('purchase_order_line_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('purchase_bill_lines', function (Blueprint $table) {
            $table->dropForeign(['purchase_order_line_id']);
            $table->dropColumn(['purchase_order_line_id', 'product_id']);
        });

        Schema::table('purchase_bills', function (Blueprint $table) {
            $table->dropForeign(['purchase_order_id']);
            $table->dropColumn(['purchase_order_id', 'match_status']);
        });

        Schema::dropIfExists('three_way_matches');
        Schema::dropIfExists('goods_receipt_lines');
        Schema::dropIfExists('goods_receipts');
        Schema::dropIfExists('purchase_order_lines');
        Schema::dropIfExists('purchase_orders');
        Schema::dropIfExists('purchase_requisition_lines');
        Schema::dropIfExists('purchase_requisitions');
    }
};
