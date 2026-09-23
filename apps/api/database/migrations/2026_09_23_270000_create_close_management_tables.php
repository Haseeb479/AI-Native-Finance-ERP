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
        // 1. Close Cycles (Period close execution tracking)
        Schema::create('close_cycles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('accounting_period_id')->constrained('accounting_periods')->cascadeOnDelete();
            
            $table->string('status', 20)->default('in_progress')->index(); // in_progress, completed, locked
            $table->unsignedSmallInteger('total_tasks_count')->default(0);
            $table->unsignedSmallInteger('completed_tasks_count')->default(0);
            $table->decimal('progress_percent', 5, 2)->default(0.00);
            
            $table->text('notes')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            
            $table->timestamps();

            $table->unique(['organization_id', 'accounting_period_id']);
        });

        // 2. Close Tasks (Granular checklist items for month-end close)
        Schema::create('close_tasks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('close_cycle_id')->constrained('close_cycles')->cascadeOnDelete();
            
            $table->string('task_key', 50)->index();
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->string('category', 50)->default('general'); // cash_banking, assets, payables, receivables, gl_review, compliance
            
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_completed')->default(false)->index();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->jsonb('metadata')->nullable();
            
            $table->timestamps();

            $table->index(['close_cycle_id', 'is_completed']);
        });

        // 3. Fixed Assets (For monthly automated depreciation schedules)
        Schema::create('fixed_assets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('asset_account_id')->constrained('accounts'); // e.g. 1510 PPE
            $table->foreignUuid('accumulated_depreciation_account_id')->constrained('accounts'); // e.g. 1520 Acc Depr
            $table->foreignUuid('depreciation_expense_account_id')->constrained('accounts'); // e.g. 5050 Depr Exp
            
            $table->string('asset_number', 50);
            $table->string('name', 200);
            $table->date('purchase_date');
            $table->decimal('purchase_cost', 15, 4);
            $table->decimal('salvage_value', 15, 4)->default(0.0000);
            $table->unsignedSmallInteger('useful_life_months')->default(60); // 5 years default
            $table->decimal('monthly_depreciation', 15, 4);
            
            $table->string('status', 20)->default('active')->index(); // active, fully_depreciated, disposed
            $table->date('last_depreciated_date')->nullable();
            
            $table->timestamps();

            $table->unique(['organization_id', 'asset_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fixed_assets');
        Schema::dropIfExists('close_tasks');
        Schema::dropIfExists('close_cycles');
    }
};
