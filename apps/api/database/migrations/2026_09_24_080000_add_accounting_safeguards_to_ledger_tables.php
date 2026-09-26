<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Add control account flags to Chart of Accounts
        Schema::table('accounts', function (Blueprint $table) {
            if (! Schema::hasColumn('accounts', 'is_control_account')) {
                $table->boolean('is_control_account')->default(false)->after('is_reconcilable');
            }
            if (! Schema::hasColumn('accounts', 'control_type')) {
                $table->string('control_type', 50)->nullable()->after('is_control_account');
            }
        });

        // 2. Add SQL-level positive decimal & mutually exclusive constraints on journal lines
        $driver = DB::getDriverName();
        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE journal_lines ADD CONSTRAINT check_journal_lines_non_negative CHECK (debit >= 0 AND credit >= 0)');
            DB::statement('ALTER TABLE journal_lines ADD CONSTRAINT check_journal_lines_mutually_exclusive CHECK ((debit > 0 AND credit = 0) OR (credit > 0 AND debit = 0))');
        } elseif ($driver === 'sqlite') {
            // SQLite table check constraints can be verified by driver or application assertion
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = DB::getDriverName();
        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE journal_lines DROP CONSTRAINT IF EXISTS check_journal_lines_mutually_exclusive');
            DB::statement('ALTER TABLE journal_lines DROP CONSTRAINT IF EXISTS check_journal_lines_non_negative');
        }

        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn(['is_control_account', 'control_type']);
        });
    }
};
