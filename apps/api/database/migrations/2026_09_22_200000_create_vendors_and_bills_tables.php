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
        Schema::create('vendors', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            
            $table->string('name', 150);
            $table->string('legal_name', 200)->nullable();
            $table->string('ntn', 30)->nullable()->index(); // Pakistan NTN
            $table->string('strn', 30)->nullable()->index(); // Pakistan STRN
            $table->string('email', 150)->nullable();
            $table->string('phone', 50)->nullable();
            
            $table->string('address_line1', 255)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('province', 100)->nullable();
            $table->string('country', 2)->default('PK');
            
            $table->unsignedSmallInteger('payment_terms_days')->default(30);
            $table->foreignUuid('default_expense_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'name']);
            $table->index(['organization_id', 'is_active']);
        });

        Schema::create('purchase_bills', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('vendor_id')->constrained('vendors');
            $table->foreignUuid('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            
            $table->string('bill_number', 50); // e.g. BILL-2025-00001
            $table->string('vendor_invoice_ref', 100)->nullable(); // Vendor external reference
            $table->date('bill_date');
            $table->date('due_date');
            $table->string('status', 20)->default('draft'); // draft, received, paid, partial, voided
            
            $table->string('currency', 3)->default('PKR');
            $table->decimal('exchange_rate', 15, 6)->default(1.000000);
            
            $table->decimal('subtotal', 15, 4)->default(0.0000);
            $table->decimal('wht_rate', 5, 2)->default(0.00); // e.g. 5.00% or 11.00% WHT
            $table->decimal('wht_amount', 15, 4)->default(0.0000);
            $table->decimal('tax_amount', 15, 4)->default(0.0000);
            $table->decimal('total_amount', 15, 4)->default(0.0000); // Gross
            $table->decimal('net_payable', 15, 4)->default(0.0000); // Total minus WHT
            $table->decimal('amount_paid', 15, 4)->default(0.0000);
            
            $table->text('notes')->nullable();
            
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'bill_number']);
            $table->index(['organization_id', 'vendor_id']);
            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'vendor_id', 'vendor_invoice_ref']);
        });

        Schema::create('purchase_bill_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('purchase_bill_id')->constrained('purchase_bills')->cascadeOnDelete();
            $table->foreignUuid('expense_account_id')->constrained('accounts');
            
            $table->unsignedSmallInteger('line_number');
            $table->text('description');
            
            $table->decimal('quantity', 12, 4)->default(1.0000);
            $table->decimal('unit_price', 15, 4)->default(0.0000);
            $table->decimal('subtotal', 15, 4)->default(0.0000);
            
            $table->timestamps();

            $table->index(['purchase_bill_id']);
            $table->index(['expense_account_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchase_bill_lines');
        Schema::dropIfExists('purchase_bills');
        Schema::dropIfExists('vendors');
    }
};
