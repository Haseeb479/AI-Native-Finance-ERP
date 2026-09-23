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
        // 1. Integrations (Third-party connectors, Payment gateways, Banks, WhatsApp, S3, Email)
        Schema::create('integrations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('provider', 50); // stripe, hbl_bank, jazzcash, easypaisa, sendgrid, s3, whatsapp, shopify, custom
            $table->string('name', 100);
            $table->string('status', 25)->default('disconnected'); // connected, disconnected, error, syncing
            $table->json('credentials')->nullable(); // encrypted API keys, tokens, webhook secrets
            $table->json('settings')->nullable(); // configuration, webhook URLs, sync intervals
            $table->string('sync_status', 25)->default('idle'); // idle, in_progress, success, failed
            $table->timestamp('last_synced_at')->nullable();
            $table->text('error_message')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'provider']);
            $table->index(['organization_id', 'status']);
        });

        // 2. Integration Sync Logs & Audit History
        Schema::create('integration_sync_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('integration_id')->constrained('integrations')->cascadeOnDelete();
            $table->string('event', 100); // payment.received, bank_feed.synced, invoice.dispatched
            $table->string('status', 25)->default('pending'); // success, failed, retrying
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->text('error_details')->nullable();
            $table->unsignedSmallInteger('retry_count')->default(0);
            $table->unsignedSmallInteger('max_retries')->default(3);

            $table->timestamps();

            $table->index(['organization_id', 'integration_id']);
            $table->index(['integration_id', 'status']);
        });

        // 3. Outbound Webhooks (Subscription endpoints with HMAC SHA-256 verification)
        Schema::create('webhooks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('url', 500);
            $table->string('secret', 128); // HMAC-SHA256 Signing secret
            $table->json('events'); // e.g. ["invoice.posted", "payment.received", "bill.approved", "stock.low"]
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('failure_count')->default(0);
            $table->timestamp('last_triggered_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'is_active']);
        });

        // 4. Webhook Deliveries (Outbound delivery audit log & retry attempts)
        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('webhook_id')->constrained('webhooks')->cascadeOnDelete();
            $table->string('event', 100);
            $table->json('payload');
            $table->string('status', 25)->default('pending'); // delivered, failed, retrying
            $table->unsignedSmallInteger('response_status_code')->nullable();
            $table->text('response_body')->nullable();
            $table->unsignedSmallInteger('attempts')->default(1);
            $table->timestamp('delivered_at')->nullable();

            $table->timestamps();

            $table->index(['organization_id', 'webhook_id']);
            $table->index(['webhook_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhooks');
        Schema::dropIfExists('integration_sync_logs');
        Schema::dropIfExists('integrations');
    }
};
