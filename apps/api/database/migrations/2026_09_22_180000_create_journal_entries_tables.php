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
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('accounting_period_id')->constrained('accounting_periods');
            
            $table->string('entry_number', 50); // e.g. JE-2025-00001
            $table->date('entry_date');
            $table->string('status', 20)->default('draft'); // draft, posted, voided
            $table->string('source_type', 50)->default('manual'); // manual, invoice, bill, payment, bank, ai_draft
            $table->string('source_id')->nullable();
            
            $table->text('description');
            $table->string('currency', 3)->default('PKR');
            $table->decimal('exchange_rate', 15, 6)->default(1.000000);
            $table->decimal('total_amount', 15, 4)->default(0.0000);
            
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            
            $table->uuid('reversal_of_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            
            $table->timestamps();

            $table->unique(['organization_id', 'entry_number']);
            $table->index(['organization_id', 'entry_date']);
            $table->index(['organization_id', 'status']);
        });

        // Add self-referencing foreign key for reversal
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->foreign('reversal_of_id')->references('id')->on('journal_entries')->nullOnDelete();
        });

        Schema::create('journal_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('journal_entry_id')->constrained('journal_entries')->cascadeOnDelete();
            $table->foreignUuid('account_id')->constrained('accounts');
            
            $table->unsignedSmallInteger('line_number');
            $table->text('description')->nullable();
            
            $table->decimal('debit', 15, 4)->default(0.0000);
            $table->decimal('credit', 15, 4)->default(0.0000);
            $table->string('currency', 3)->default('PKR');
            
            $table->timestamps();

            $table->index(['organization_id', 'account_id']);
            $table->index(['journal_entry_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('journal_lines');
        
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropForeign(['reversal_of_id']);
        });
        
        Schema::dropIfExists('journal_entries');
    }
};
