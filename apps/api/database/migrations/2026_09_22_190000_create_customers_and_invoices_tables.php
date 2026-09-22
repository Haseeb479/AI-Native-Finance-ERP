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
        Schema::create('customers', function (Blueprint $table) {
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
            $table->decimal('credit_limit', 15, 4)->nullable();
            $table->boolean('is_active')->default(true);
            
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'name']);
            $table->index(['organization_id', 'is_active']);
        });

        Schema::create('sales_invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('customer_id')->constrained('customers');
            $table->foreignUuid('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            
            $table->string('invoice_number', 50); // e.g. INV-2025-00001
            $table->date('issue_date');
            $table->date('due_date');
            $table->string('status', 20)->default('draft'); // draft, sent, paid, partial, voided
            
            $table->string('currency', 3)->default('PKR');
            $table->decimal('exchange_rate', 15, 6)->default(1.000000);
            
            $table->decimal('subtotal', 15, 4)->default(0.0000);
            $table->decimal('tax_rate', 5, 2)->default(0.00);
            $table->decimal('tax_amount', 15, 4)->default(0.0000);
            $table->decimal('total_amount', 15, 4)->default(0.0000);
            $table->decimal('amount_paid', 15, 4)->default(0.0000);
            
            $table->text('notes')->nullable();
            $table->text('terms')->nullable();
            
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'invoice_number']);
            $table->index(['organization_id', 'customer_id']);
            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'issue_date']);
        });

        Schema::create('sales_invoice_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('sales_invoice_id')->constrained('sales_invoices')->cascadeOnDelete();
            $table->foreignUuid('revenue_account_id')->constrained('accounts');
            
            $table->unsignedSmallInteger('line_number');
            $table->text('description');
            
            $table->decimal('quantity', 12, 4)->default(1.0000);
            $table->decimal('unit_price', 15, 4)->default(0.0000);
            $table->decimal('tax_rate', 5, 2)->default(0.00);
            $table->decimal('tax_amount', 15, 4)->default(0.0000);
            $table->decimal('subtotal', 15, 4)->default(0.0000);
            $table->decimal('total', 15, 4)->default(0.0000);
            
            $table->timestamps();

            $table->index(['sales_invoice_id']);
            $table->index(['revenue_account_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sales_invoice_lines');
        Schema::dropIfExists('sales_invoices');
        Schema::dropIfExists('customers');
    }
};
