<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations for the Transactional Outbox pattern (P2-08).
     */
    public function up(): void
    {
        Schema::create('outbox_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('event_type')->index();
            $table->string('aggregate_type')->nullable()->index();
            $table->string('aggregate_id')->nullable()->index();
            $table->string('organization_id')->nullable()->index();
            $table->string('correlation_id')->nullable()->index();
            $table->json('payload');
            $table->string('status', 32)->default('pending')->index(); // pending, processing, published, failed
            $table->unsignedInteger('retry_count')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('outbox_events');
    }
};
