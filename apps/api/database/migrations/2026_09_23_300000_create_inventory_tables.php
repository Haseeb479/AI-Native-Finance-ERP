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
        // 1. Product Categories
        Schema::create('product_categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('slug', 100);
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'slug']);
        });

        // 2. Products / SKUs
        Schema::create('products', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('category_id')->nullable()->constrained('product_categories')->nullOnDelete();
            $table->string('sku', 50); // Stock Keeping Unit
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->string('unit_of_measure', 20)->default('pcs'); // pcs, box, kg, etc.
            
            // Financial COA linkage
            $table->foreignUuid('inventory_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->foreignUuid('cogs_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->foreignUuid('revenue_account_id')->nullable()->constrained('accounts')->nullOnDelete();

            $table->string('valuation_method', 20)->default('weighted_average'); // weighted_average, fifo
            $table->decimal('cost_price', 15, 4)->default(0.0000);
            $table->decimal('selling_price', 15, 4)->default(0.0000);
            $table->decimal('reorder_level', 12, 4)->default(0.0000);
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'sku']);
            $table->index(['organization_id', 'is_active']);
        });

        // 3. Warehouses
        Schema::create('warehouses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('code', 50); // e.g. WH-KHI-01
            $table->string('name', 100);
            $table->string('city', 100)->nullable();
            $table->text('address')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'code']);
        });

        // 4. Warehouse Stock (Current Balance)
        Schema::create('warehouse_stock', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignUuid('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            
            $table->decimal('quantity_on_hand', 14, 4)->default(0.0000);
            $table->decimal('quantity_reserved', 14, 4)->default(0.0000);
            $table->decimal('weighted_average_cost', 15, 4)->default(0.0000);
            $table->decimal('total_valuation', 15, 4)->default(0.0000);

            $table->timestamps();

            $table->unique(['product_id', 'warehouse_id']);
            $table->index(['organization_id', 'product_id']);
        });

        // 5. Stock Movements (Perpetual Inventory Ledger)
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignUuid('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            
            $table->string('movement_type', 30); // receipt, dispatch, adjustment, transfer
            $table->string('reference_type', 50)->nullable(); // goods_receipt, sales_invoice, manual_adjustment, transfer
            $table->uuid('reference_id')->nullable();
            
            $table->decimal('quantity', 14, 4); // positive for in, negative for out
            $table->decimal('unit_cost', 15, 4)->default(0.0000);
            $table->decimal('total_cost', 15, 4)->default(0.0000);
            $table->decimal('balance_after', 14, 4)->default(0.0000);
            $table->text('notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'product_id']);
            $table->index(['organization_id', 'warehouse_id']);
            $table->index(['organization_id', 'movement_type']);
        });

        // 6. Inventory Valuation Layers (FIFO lot tracking)
        Schema::create('inventory_valuation_layers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignUuid('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            
            $table->decimal('quantity_received', 14, 4);
            $table->decimal('quantity_remaining', 14, 4);
            $table->decimal('unit_cost', 15, 4);
            $table->timestamp('received_at');

            $table->timestamps();

            $table->index(['organization_id', 'product_id', 'warehouse_id']);
            $table->index(['quantity_remaining']);
        });

        // 7. Add product_id and warehouse_id to sales_invoice_lines
        Schema::table('sales_invoice_lines', function (Blueprint $table) {
            $table->foreignUuid('product_id')->nullable()->after('revenue_account_id')->constrained('products')->nullOnDelete();
            $table->foreignUuid('warehouse_id')->nullable()->after('product_id')->constrained('warehouses')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales_invoice_lines', function (Blueprint $table) {
            $table->dropForeign(['product_id']);
            $table->dropForeign(['warehouse_id']);
            $table->dropColumn(['product_id', 'warehouse_id']);
        });

        Schema::dropIfExists('inventory_valuation_layers');
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('warehouse_stock');
        Schema::dropIfExists('warehouses');
        Schema::dropIfExists('products');
        Schema::dropIfExists('product_categories');
    }
};
