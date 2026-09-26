<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Create dedicated journal_sequences table for high-concurrency numbering
        if (! Schema::hasTable('journal_sequences')) {
            Schema::create('journal_sequences', function (Blueprint $table) {
                $table->id();
                $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
                $table->string('prefix', 50);
                $table->unsignedBigInteger('current_sequence')->default(0);
                $table->timestamps();

                $table->unique(['organization_id', 'prefix']);
            });
        }

        // 2. Add idempotency_key to journal_entries for financial mutation protection
        if (! Schema::hasColumn('journal_entries', 'idempotency_key')) {
            Schema::table('journal_entries', function (Blueprint $table) {
                $table->string('idempotency_key', 100)->nullable()->after('entry_number');
                $table->unique(['organization_id', 'idempotency_key']);
            });
        }

        // 3. Add DB-level check constraints on journal_lines (Postgres)
        $driver = DB::getDriverName();
        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE journal_lines DROP CONSTRAINT IF EXISTS check_debit_positive');
            DB::statement('ALTER TABLE journal_lines DROP CONSTRAINT IF EXISTS check_credit_positive');
            DB::statement('ALTER TABLE journal_lines DROP CONSTRAINT IF EXISTS check_line_not_zero');
            DB::statement('ALTER TABLE journal_lines DROP CONSTRAINT IF EXISTS check_mutually_exclusive');

            DB::statement('ALTER TABLE journal_lines ADD CONSTRAINT check_debit_positive CHECK (debit >= 0)');
            DB::statement('ALTER TABLE journal_lines ADD CONSTRAINT check_credit_positive CHECK (credit >= 0)');
            DB::statement('ALTER TABLE journal_lines ADD CONSTRAINT check_line_not_zero CHECK (debit > 0 OR credit > 0)');
            DB::statement('ALTER TABLE journal_lines ADD CONSTRAINT check_mutually_exclusive CHECK (NOT (debit > 0 AND credit > 0))');
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();
        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE journal_lines DROP CONSTRAINT IF EXISTS check_mutually_exclusive');
            DB::statement('ALTER TABLE journal_lines DROP CONSTRAINT IF EXISTS check_line_not_zero');
            DB::statement('ALTER TABLE journal_lines DROP CONSTRAINT IF EXISTS check_credit_positive');
            DB::statement('ALTER TABLE journal_lines DROP CONSTRAINT IF EXISTS check_debit_positive');
        }

        if (Schema::hasColumn('journal_entries', 'idempotency_key')) {
            Schema::table('journal_entries', function (Blueprint $table) {
                $table->dropUnique(['organization_id', 'idempotency_key']);
                $table->dropColumn('idempotency_key');
            });
        }

        Schema::dropIfExists('journal_sequences');
    }
};
