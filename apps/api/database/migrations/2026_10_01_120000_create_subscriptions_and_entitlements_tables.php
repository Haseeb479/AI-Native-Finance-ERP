<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations for SaaS subscriptions and usage metering (P2-28, P2-29, P2-30).
     */
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->string('id')->primary(); // starter, growth, enterprise
            $table->string('name');
            $table->decimal('price_monthly', 12, 4)->default(0);
            $table->string('currency', 3)->default('PKR');
            $table->unsignedInteger('max_seats')->default(5);
            $table->unsignedInteger('monthly_transaction_limit')->default(500);
            $table->unsignedInteger('monthly_ai_query_limit')->default(200);
            $table->unsignedInteger('max_storage_mb')->default(1024);
            $table->json('features');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('plan_id');
            $table->string('status', 32)->default('active'); // active, past_due, canceled, trialing
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->timestamps();

            $table->foreign('plan_id')->references('id')->on('plans')->cascadeOnDelete();
        });

        Schema::create('usage_meters', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('metric_name', 64); // transactions_count, ai_queries, documents_uploaded, storage_bytes
            $table->string('billing_period', 7); // YYYY-MM
            $table->unsignedBigInteger('usage_count')->default(0);
            $table->timestamps();

            $table->unique(['organization_id', 'metric_name', 'billing_period']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('usage_meters');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('plans');
    }
};
