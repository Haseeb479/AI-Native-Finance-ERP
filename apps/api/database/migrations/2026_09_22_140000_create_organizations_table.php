<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Organizations (Top-level multi-tenant account)
        Schema::create('organizations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('legal_name');
            $table->string('ntn', 30)->nullable()->index(); // Pakistan National Tax Number
            $table->string('strn', 30)->nullable()->index(); // Pakistan Sales Tax Registration Number
            $table->string('country_code', 2)->default('PK');
            $table->string('base_currency', 3)->default('PKR');
            $table->unsignedTinyInteger('fiscal_year_start_month')->default(7); // July for Pakistan
            $table->string('status', 20)->default('active')->index();
            $table->jsonb('settings')->nullable();
            $table->timestamps();
        });

        // 2. Organization User Pivot (Tenant membership)
        Schema::create('organization_user', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 30)->default('owner'); // owner, admin, accountant, finance_manager, staff, auditor
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->unique(['organization_id', 'user_id']);
        });

        // 3. Legal Entities (Subsidiaries, parent companies, standalone entities)
        Schema::create('entities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 20);
            $table->string('currency', 3)->default('PKR');
            $table->boolean('is_primary')->default(false);
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->unique(['organization_id', 'code']);
            $table->index(['organization_id', 'status']);
        });

        // 4. Branches (Geographical locations / operating units)
        Schema::create('branches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 20);
            $table->string('city')->nullable();
            $table->text('address')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->unique(['entity_id', 'code']);
            $table->index(['organization_id', 'entity_id']);
        });

        // 5. Departments (Cost centers / organizational units)
        Schema::create('departments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('name');
            $table->string('code', 20);
            $table->timestamps();

            $table->unique(['organization_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('departments');
        Schema::dropIfExists('branches');
        Schema::dropIfExists('entities');
        Schema::dropIfExists('organization_user');
        Schema::dropIfExists('organizations');
    }
};
