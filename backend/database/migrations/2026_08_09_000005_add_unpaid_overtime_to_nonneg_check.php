<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Brings the M6c `unpaid_overtime_minutes` column under the same non-negativity invariant
 * every other minute column on the summary already carries. The column was added (M6c
 * Task 6) with a DEFAULT 0 but was not folded into `das_minutes_nonneg_check`, leaving it
 * the one minutes column without the guard — a value that is non-negative by construction
 * (excess is the duration of a worked interval) but should still be enforced at the
 * database, consistently with scheduled/worked/late/undertime.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE daily_attendance_summaries DROP CONSTRAINT das_minutes_nonneg_check');
        DB::statement('ALTER TABLE daily_attendance_summaries ADD CONSTRAINT das_minutes_nonneg_check CHECK (scheduled_minutes >= 0 AND worked_minutes >= 0 AND late_minutes >= 0 AND undertime_minutes >= 0 AND unpaid_overtime_minutes >= 0)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE daily_attendance_summaries DROP CONSTRAINT das_minutes_nonneg_check');
        DB::statement('ALTER TABLE daily_attendance_summaries ADD CONSTRAINT das_minutes_nonneg_check CHECK (scheduled_minutes >= 0 AND worked_minutes >= 0 AND late_minutes >= 0 AND undertime_minutes >= 0)');
    }
};
