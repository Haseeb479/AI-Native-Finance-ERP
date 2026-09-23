<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_prompt_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->nullable()->constrained('organizations')->cascadeOnDelete();
            $table->string('key', 100)->index();
            $table->unsignedInteger('version')->default(1);
            $table->text('system_prompt');
            $table->text('user_prompt_template');
            $table->string('model_target', 100)->default('gemini-1.5-flash');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'key', 'version']);
        });

        Schema::create('ai_run_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('prompt_key', 100)->index();
            $table->unsignedInteger('prompt_version')->default(1);
            $table->string('provider', 50)->default('gemini');
            $table->string('model', 100);
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->decimal('total_cost', 8, 6)->default(0.000000);
            $table->string('status', 30)->default('success'); // success, failed, rate_limited
            $table->unsignedInteger('latency_ms')->default(0);
            $table->text('error_message')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'created_at']);
            $table->index(['organization_id', 'prompt_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_run_logs');
        Schema::dropIfExists('ai_prompt_templates');
    }
};
