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
        // 1. Organization API Keys (Hashed secrets, secret rotation, IP allowlist, rate limits)
        Schema::create('security_api_keys', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('key_prefix', 20)->default('erp_live_');
            $table->string('key_hash', 64); // SHA-256 hash of secret key
            $table->unsignedInteger('rate_limit_per_minute')->default(60);
            $table->json('allowed_ips')->nullable(); // Optional IP whitelist
            $table->boolean('is_active')->default(true);
            $table->timestamp('secret_last_rotated_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'is_active']);
            $table->index(['key_hash']);
        });

        // 2. Security Audit Events / Rate Limit Breaches
        Schema::create('security_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->string('event_type', 50); // rate_limit_exceeded, unauthorized_tenant_access, secret_rotated, ip_blocked
            $table->string('severity', 20)->default('info'); // info, warning, high, critical
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->json('details')->nullable();

            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'event_type']);
            $table->index(['created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('security_events');
        Schema::dropIfExists('security_api_keys');
    }
};
