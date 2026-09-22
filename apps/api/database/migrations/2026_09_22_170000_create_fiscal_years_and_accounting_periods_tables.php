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
        Schema::create('fiscal_years', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            
            $table->string('name', 50); // e.g. "FY 2025-2026"
            $table->date('start_date');
            $table->date('end_date');
            
            $table->boolean('is_closed')->default(false);
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            
            $table->timestamps();

            $table->unique(['organization_id', 'name']);
            $table->index(['organization_id', 'start_date', 'end_date']);
        });

        Schema::create('accounting_periods', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('fiscal_year_id')->constrained('fiscal_years')->cascadeOnDelete();
            
            $table->unsignedTinyInteger('period_number'); // 1 to 12
            $table->string('name', 50); // e.g. "July 2025"
            $table->date('start_date');
            $table->date('end_date');
            
            $table->string('status', 20)->default('open'); // open, closed, locked
            
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            
            $table->timestamp('reopened_at')->nullable();
            $table->foreignId('reopened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reopen_reason')->nullable();
            
            $table->timestamps();

            $table->unique(['organization_id', 'fiscal_year_id', 'period_number']);
            $table->index(['organization_id', 'start_date', 'end_date']);
            $table->index(['organization_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('accounting_periods');
        Schema::dropIfExists('fiscal_years');
    }
};
