<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Widens recompute_runs' `trigger_type` CHECK to admit `overtime` — OvertimeEffect
 * enqueues a recompute over the approved request's single authorized date so
 * ComputeDailySummary re-prices that day under the now-approved overtime cap, mirroring
 * how leave (and before it, holiday/pay_rule/shift_template/schedule) already triggers one.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE recompute_runs DROP CONSTRAINT recompute_runs_trigger_type_check');
        DB::statement("ALTER TABLE recompute_runs ADD CONSTRAINT recompute_runs_trigger_type_check CHECK (trigger_type IN ('holiday','pay_rule','shift_template','schedule_assignment','schedule_override','office_default','leave','overtime'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE recompute_runs DROP CONSTRAINT recompute_runs_trigger_type_check');
        DB::statement("ALTER TABLE recompute_runs ADD CONSTRAINT recompute_runs_trigger_type_check CHECK (trigger_type IN ('holiday','pay_rule','shift_template','schedule_assignment','schedule_override','office_default','leave'))");
    }
};
