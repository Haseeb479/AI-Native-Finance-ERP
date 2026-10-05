<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('control_center_support_cases', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->nullable()->index();
            $table->string('customer_email', 254)->index();
            $table->string('category', 32)->index();
            $table->string('subject', 200);
            $table->text('description');
            $table->string('priority', 16)->default('normal')->index();
            $table->string('status', 24)->default('open')->index();
            $table->string('created_by_user_id', 64);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('control_center_support_cases');
    }
};
