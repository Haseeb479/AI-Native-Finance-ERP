<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_audit_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('action', 160);
            $table->uuid('organization_id')->nullable();
            $table->string('outcome', 16);
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 1000)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['actor_user_id', 'created_at']);
            $table->index(['organization_id', 'created_at']);
        });

        $driver = DB::connection()->getDriverName();
        if ($driver === 'sqlite') {
            DB::unprepared("CREATE TRIGGER staff_audit_events_no_update BEFORE UPDATE ON staff_audit_events BEGIN SELECT RAISE(ABORT, 'staff audit events are append-only'); END");
            DB::unprepared("CREATE TRIGGER staff_audit_events_no_delete BEFORE DELETE ON staff_audit_events BEGIN SELECT RAISE(ABORT, 'staff audit events are append-only'); END");
        } elseif ($driver === 'pgsql') {
            DB::unprepared("CREATE FUNCTION prevent_staff_audit_event_mutation() RETURNS trigger AS $$ BEGIN RAISE EXCEPTION 'staff audit events are append-only'; END; $$ LANGUAGE plpgsql");
            DB::unprepared('CREATE TRIGGER staff_audit_events_no_mutation BEFORE UPDATE OR DELETE ON staff_audit_events FOR EACH ROW EXECUTE FUNCTION prevent_staff_audit_event_mutation()');
        } elseif ($driver === 'mysql') {
            DB::unprepared("CREATE TRIGGER staff_audit_events_no_update BEFORE UPDATE ON staff_audit_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'staff audit events are append-only'");
            DB::unprepared("CREATE TRIGGER staff_audit_events_no_delete BEFORE DELETE ON staff_audit_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'staff audit events are append-only'");
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'sqlite' || $driver === 'mysql') {
            DB::unprepared('DROP TRIGGER IF EXISTS staff_audit_events_no_update');
            DB::unprepared('DROP TRIGGER IF EXISTS staff_audit_events_no_delete');
        } elseif ($driver === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS staff_audit_events_no_mutation ON staff_audit_events');
            DB::unprepared('DROP FUNCTION IF EXISTS prevent_staff_audit_event_mutation()');
        }

        Schema::dropIfExists('staff_audit_events');
    }
};
