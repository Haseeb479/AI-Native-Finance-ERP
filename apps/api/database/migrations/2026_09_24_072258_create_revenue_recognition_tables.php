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
        // 1. Revenue Contracts
        Schema::create('revenue_contracts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('contract_number', 50)->index();
            $table->string('title');
            $table->date('start_date');
            $table->date('end_date');
            $table->decimal('total_contract_value', 18, 4);
            $table->string('currency', 3)->default('PKR');
            $table->string('recognition_method', 30)->default('straight_line'); // straight_line, milestone, usage
            $table->string('status', 20)->default('draft'); // draft, active, completed, cancelled
            $table->foreignUuid('deferred_revenue_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignUuid('revenue_account_id')->constrained('accounts')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'contract_number']);
            $table->index(['organization_id', 'status']);
        });

        // 2. Revenue Recognition Schedules (Monthly amortizations / Milestone schedules)
        Schema::create('revenue_schedules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('revenue_contract_id')->constrained('revenue_contracts')->cascadeOnDelete();
            $table->foreignUuid('accounting_period_id')->nullable()->constrained('accounting_periods')->nullOnDelete();
            $table->date('schedule_date');
            $table->decimal('amount', 18, 4);
            $table->decimal('cumulative_recognized', 18, 4)->default(0.0000);
            $table->string('status', 20)->default('pending'); // pending, posted, cancelled
            $table->foreignUuid('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->timestamp('recognized_at')->nullable();
            $table->foreignUuid('recognized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index(['revenue_contract_id', 'schedule_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('revenue_schedules');
        Schema::dropIfExists('revenue_contracts');
    }
};
