<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Permissions Master Table
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique(); // e.g. 'accounting.journal.post'
            $table->string('group', 50);      // e.g. 'accounting', 'sales', 'users'
            $table->string('description');
            $table->timestamps();
        });

        // 2. Roles Master Table
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique(); // 'owner', 'admin', 'accountant', 'finance_manager', 'staff', 'auditor'
            $table->string('label');
            $table->text('description')->nullable();
            $table->timestamps();
        });

        // 3. Role-Permission Pivot Table
        Schema::create('role_permission', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained('permissions')->cascadeOnDelete();
            $table->primary(['role_id', 'permission_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_permission');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('permissions');
    }
};
