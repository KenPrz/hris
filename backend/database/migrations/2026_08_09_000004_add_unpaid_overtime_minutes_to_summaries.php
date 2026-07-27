<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Worked overtime minutes that fell beyond the pre-authorized cap — unpaid, shown, never
 * priced. A day-level scalar beside late_minutes/undertime_minutes (the same species: a
 * non-premium magnitude, not a priced daily_summary_lines row). Zero on every day with no
 * excess, so the default backfills every existing row cleanly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_attendance_summaries', function (Blueprint $table): void {
            $table->integer('unpaid_overtime_minutes')->default(0)->after('undertime_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('daily_attendance_summaries', function (Blueprint $table): void {
            $table->dropColumn('unpaid_overtime_minutes');
        });
    }
};
