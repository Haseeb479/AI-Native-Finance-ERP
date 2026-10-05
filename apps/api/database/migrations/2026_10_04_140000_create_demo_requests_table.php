<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demo_requests', function (Blueprint $table) {
            $table->id();
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('email', 254)->index();
            $table->string('company_name', 200);
            $table->string('company_size', 40);
            $table->string('role', 120)->nullable();
            $table->string('referral_source', 120)->nullable();
            $table->text('message')->nullable();
            $table->string('status', 24)->default('new')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_requests');
    }
};
