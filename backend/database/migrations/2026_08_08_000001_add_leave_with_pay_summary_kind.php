<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Widens daily_summary_lines' `dsl_kind_check` to admit `leave_with_pay` — the flat-100%
 * line ComputeDailySummary/DailyComputation emit for a scheduled working day covered by an
 * APPROVED full-day leave request with no punches.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE daily_summary_lines DROP CONSTRAINT dsl_kind_check');
        DB::statement("ALTER TABLE daily_summary_lines ADD CONSTRAINT dsl_kind_check CHECK (kind IN ('regular_day','regular_night','overtime_day','overtime_night','holiday_unworked','leave_with_pay'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE daily_summary_lines DROP CONSTRAINT dsl_kind_check');
        DB::statement("ALTER TABLE daily_summary_lines ADD CONSTRAINT dsl_kind_check CHECK (kind IN ('regular_day','regular_night','overtime_day','overtime_night','holiday_unworked'))");
    }
};
