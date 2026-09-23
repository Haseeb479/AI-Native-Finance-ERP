<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();

            // Identification
            $table->string('document_number', 50)->index(); // e.g. DOC-2025-00001
            $table->string('document_type', 30); // invoice, receipt, statement, contract, other

            // File storage (private — never a public URL)
            $table->string('original_filename', 255);
            $table->string('storage_disk', 30)->default('s3');
            $table->string('storage_path', 500); // private internal path
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('file_size_bytes');

            // OCR pipeline
            $table->string('ocr_status', 20)->default('pending'); // pending, processing, completed, failed, skipped
            $table->text('ocr_raw_text')->nullable();             // raw OCR/extracted text (before sanitization)
            $table->boolean('ocr_was_flagged')->default(false);   // prompt injection detected
            $table->jsonb('extracted_data')->nullable();          // structured AI-extracted fields
            $table->decimal('extraction_confidence', 3, 2)->nullable(); // 0.00–1.00

            // Human review workflow
            $table->string('human_review_status', 20)->default('not_required'); // not_required, pending, approved, rejected
            $table->foreignId('human_reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('human_reviewed_at')->nullable();
            $table->text('human_review_notes')->nullable();

            // Polymorphic link to business document (SalesInvoice, PurchaseBill, etc.)
            $table->string('linkable_type', 100)->nullable();
            $table->uuid('linkable_id')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->unique(['organization_id', 'document_number']);
            $table->index(['organization_id', 'document_type']);
            $table->index(['organization_id', 'ocr_status']);
            $table->index(['organization_id', 'human_review_status']);
            $table->index(['linkable_type', 'linkable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
