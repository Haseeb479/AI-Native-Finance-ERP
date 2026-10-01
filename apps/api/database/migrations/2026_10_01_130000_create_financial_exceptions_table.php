<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations for the first-class FinancialException queue (P3-01).
     */
    public function up(): void
    {
        Schema::create('financial_exceptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('entity_id')->nullable()->index();
            $table->string('exception_type', 64)->index(); 
            // Types: unmatched_bank_transaction, duplicate_invoice, low_ocr_confidence, missing_document,
            // tax_mismatch, unusual_expense, integration_failure, revenue_exception, closed_period_conflict
            $table->string('severity', 16)->default('medium')->index(); // low, medium, high, critical
            $table->string('aggregate_type', 64)->nullable()->index();
            $table->string('aggregate_id', 64)->nullable()->index();
            $table->string('title');
            $table->text('description')->nullable();
            $table->json('payload')->nullable();
            $table->string('status', 32)->default('open')->index(); // open, under_review, resolved, dismissed
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('resolution_notes')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('financial_exceptions');
    }
};
