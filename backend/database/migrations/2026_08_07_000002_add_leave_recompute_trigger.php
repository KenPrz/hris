<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Widens recompute_runs' `trigger_type` CHECK to admit `leave` — LeaveEffect enqueues a
 * recompute over the approved leave span so Task 9's compute step prices the days,
 * mirroring how a holiday/pay_rule/shift_template/schedule change already triggers one.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE recompute_runs DROP CONSTRAINT recompute_runs_trigger_type_check');
        DB::statement("ALTER TABLE recompute_runs ADD CONSTRAINT recompute_runs_trigger_type_check CHECK (trigger_type IN ('holiday','pay_rule','shift_template','schedule_assignment','schedule_override','office_default','leave'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE recompute_runs DROP CONSTRAINT recompute_runs_trigger_type_check');
        DB::statement("ALTER TABLE recompute_runs ADD CONSTRAINT recompute_runs_trigger_type_check CHECK (trigger_type IN ('holiday','pay_rule','shift_template','schedule_assignment','schedule_override','office_default'))");
    }
};
