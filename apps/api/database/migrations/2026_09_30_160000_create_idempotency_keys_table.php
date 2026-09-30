<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * P1-22: Enterprise Idempotency Keys table for all financial mutations.
     */
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('idempotency_key', 255);
            $table->string('request_method', 10);
            $table->string('request_path', 1000);
            $table->string('request_hash', 64);
            $table->enum('status', ['in_progress', 'completed', 'failed'])->default('in_progress');
            $table->unsignedSmallInteger('response_code')->nullable();
            $table->json('response_headers')->nullable();
            $table->json('response_body')->nullable();
            $table->timestamp('locked_at')->useCurrent();
            $table->timestamps();

            $table->unique(['organization_id', 'idempotency_key'], 'uniq_org_idempotency_key');
            $table->index(['organization_id', 'status', 'created_at'], 'idx_org_status_created');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
