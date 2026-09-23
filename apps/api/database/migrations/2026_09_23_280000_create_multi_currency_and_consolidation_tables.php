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
        // 1. Add entity_id to journal_entries
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->foreignUuid('entity_id')->nullable()->after('organization_id')->constrained('entities')->nullOnDelete();
        });

        // 2. Exchange Rates Table
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            
            $table->string('from_currency', 3)->index();
            $table->string('to_currency', 3)->index();
            $table->decimal('rate', 15, 6);
            $table->date('effective_date')->index();
            $table->string('source', 50)->default('manual'); // manual, state_bank_pakistan, ecb, api
            
            $table->timestamps();

            $table->unique(['organization_id', 'from_currency', 'to_currency', 'effective_date']);
        });

        // 3. Intercompany Transactions Table
        Schema::create('intercompany_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            
            $table->foreignUuid('from_entity_id')->constrained('entities')->cascadeOnDelete();
            $table->foreignUuid('to_entity_id')->constrained('entities')->cascadeOnDelete();
            
            $table->string('transaction_number', 50); // e.g. IC-2025-0001
            $table->date('transaction_date');
            $table->string('currency', 3)->default('PKR');
            $table->decimal('amount', 15, 4);
            $table->decimal('exchange_rate', 15, 6)->default(1.000000);
            $table->decimal('base_amount', 15, 4);
            $table->text('description');
            
            $table->string('status', 20)->default('draft')->index(); // draft, posted, eliminated
            
            $table->foreignUuid('from_journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignUuid('to_journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignUuid('elimination_journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->timestamp('eliminated_at')->nullable();
            
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'transaction_number']);
            $table->index(['organization_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('intercompany_transactions');
        Schema::dropIfExists('exchange_rates');

        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropForeign(['entity_id']);
            $table->dropColumn('entity_id');
        });
    }
};
