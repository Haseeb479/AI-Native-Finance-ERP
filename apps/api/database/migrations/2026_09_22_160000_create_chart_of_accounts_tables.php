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
        // Account types (System definitions: asset, liability, equity, revenue, expense)
        Schema::create('account_types', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50);
            $table->string('slug', 50)->unique();
            $table->string('classification', 20); // asset, liability, equity, revenue, expense
            $table->string('normal_balance', 10); // debit, credit
            $table->text('description')->nullable();
            $table->timestamps();
        });

        // Account groups / categories (Current Assets, Non-Current Assets, Current Liabilities, etc.)
        Schema::create('account_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_type_id')->constrained('account_types')->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('slug', 100);
            $table->text('description')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['account_type_id', 'slug']);
        });

        // Accounts table
        Schema::create('accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('account_type_id')->constrained('account_types');
            $table->foreignId('account_group_id')->nullable()->constrained('account_groups');
            $table->uuid('parent_account_id')->nullable();
            
            $table->string('code', 50);
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->string('classification', 20); // asset, liability, equity, revenue, expense
            $table->string('normal_balance', 10); // debit, credit
            $table->string('currency', 3)->default('PKR');
            
            $table->boolean('is_active')->default(true);
            $table->boolean('is_reconcilable')->default(false); // Bank / AR / AP
            $table->boolean('is_system')->default(false); // Retained earnings, suspense, etc.
            
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'code']);
            $table->index(['organization_id', 'classification']);
            $table->index(['organization_id', 'is_active']);
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('accounts');
        Schema::dropIfExists('account_groups');
        Schema::dropIfExists('account_types');
    }
};
