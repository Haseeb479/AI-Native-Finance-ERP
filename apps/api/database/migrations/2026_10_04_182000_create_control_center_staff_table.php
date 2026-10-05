<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('control_center_staff', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('role', 32);
            $table->string('status', 16)->default('active')->index();
            $table->unsignedBigInteger('provisioned_by_user_id');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('control_center_staff');
    }
};
