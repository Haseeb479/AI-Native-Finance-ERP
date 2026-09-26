<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Enhance ai_run_logs table with provider metrics (P1-16)
        Schema::table('ai_run_logs', function (Blueprint $table) {
            $table->unsignedInteger('cached_tokens')->default(0)->after('output_tokens');
            $table->string('provider_request_id', 100)->nullable()->after('model');
            $table->unsignedSmallInteger('retry_count')->default(0)->after('latency_ms');
        });

        // 2. Create ai_quotas table for multi-tenant budget and token enforcement (P1-17)
        Schema::create('ai_quotas', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('feature', 50)->default('all');
            $table->unsignedBigInteger('monthly_token_quota')->default(500000);
            $table->decimal('monthly_spend_quota', 10, 4)->default(50.0000);
            $table->unsignedBigInteger('tokens_used_this_month')->default(0);
            $table->decimal('spend_used_this_month', 10, 4)->default(0.0000);
            $table->boolean('hard_limit_enabled')->default(true);
            $table->unsignedTinyInteger('soft_alert_threshold_percent')->default(80);
            $table->date('last_reset_date')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'feature', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_quotas');
        Schema::table('ai_run_logs', function (Blueprint $table) {
            $table->dropColumn(['cached_tokens', 'provider_request_id', 'retry_count']);
        });
    }
};
