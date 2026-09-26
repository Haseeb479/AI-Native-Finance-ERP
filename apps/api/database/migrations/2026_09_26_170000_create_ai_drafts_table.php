<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_drafts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('entity_id')->nullable()->constrained('entities')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('draft_type', 60)->index(); // journal_entry, invoice_draft, bill_draft, adjustment
            $table->string('title', 255);
            $table->json('input_context')->nullable();
            $table->json('proposed_payload');
            $table->json('validation_result')->nullable();
            $table->json('evidence')->nullable();
            $table->string('status', 40)->default('pending_review')->index(); // pending_review, approved, rejected, posted
            $table->text('rejection_reason')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->string('resulting_record_type', 100)->nullable();
            $table->uuid('resulting_record_id')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'draft_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_drafts');
    }
};
