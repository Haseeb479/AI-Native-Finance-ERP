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
        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('account_id')->constrained('accounts'); // Linked GL Account (e.g. 1020 Meezan Bank)
            
            $table->string('bank_name', 100); // e.g. Meezan Bank, HBL
            $table->string('account_title', 150); // e.g. Shah Wholesale Operating A/C
            $table->string('account_number', 50);
            $table->string('iban', 34)->nullable()->index();
            $table->string('branch_name', 100)->nullable();
            $table->string('branch_code', 20)->nullable();
            
            $table->string('currency', 3)->default('PKR');
            $table->decimal('opening_balance', 15, 4)->default(0.0000);
            $table->decimal('current_balance', 15, 4)->default(0.0000);
            $table->boolean('is_active')->default(true);
            
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'account_number']);
            $table->index(['organization_id', 'is_active']);
        });

        Schema::create('bank_statements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('bank_account_id')->constrained('bank_accounts')->cascadeOnDelete();
            
            $table->string('statement_number', 50); // e.g. STMT-2025-0001
            $table->date('from_date');
            $table->date('to_date');
            $table->decimal('opening_balance', 15, 4)->default(0.0000);
            $table->decimal('closing_balance', 15, 4)->default(0.0000);
            $table->string('source_file_name', 255)->nullable();
            $table->string('status', 20)->default('imported'); // imported, partially_reconciled, reconciled
            
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'bank_account_id']);
            $table->index(['organization_id', 'status']);
        });

        Schema::create('bank_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('bank_account_id')->constrained('bank_accounts')->cascadeOnDelete();
            $table->foreignUuid('bank_statement_id')->nullable()->constrained('bank_statements')->nullOnDelete();
            
            $table->date('transaction_date');
            $table->text('description');
            $table->string('reference', 100)->nullable()->index(); // Cheque #, IBFT Ref, RAAST ID
            $table->string('type', 10); // debit (withdrawal) or credit (deposit)
            $table->decimal('amount', 15, 4);
            $table->decimal('balance_after', 15, 4)->nullable();
            
            $table->string('reconciliation_status', 20)->default('unreconciled'); // unreconciled, matched, reconciled, disputed
            $table->foreignUuid('matched_journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->timestamp('matched_at')->nullable();
            $table->foreignId('matched_by')->nullable()->constrained('users')->nullOnDelete();
            
            $table->string('fingerprint', 64)->index(); // SHA256 to prevent duplicate statement rows
            
            $table->timestamps();

            $table->index(['organization_id', 'bank_account_id', 'transaction_date']);
            $table->index(['organization_id', 'reconciliation_status']);
            $table->unique(['organization_id', 'bank_account_id', 'fingerprint']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bank_transactions');
        Schema::dropIfExists('bank_statements');
        Schema::dropIfExists('bank_accounts');
    }
};
