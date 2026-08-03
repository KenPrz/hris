<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The per-day quantity a leave request pays and debits.
 *
 * `amount_minutes` is the request TOTAL (scheduled working days x per-day), which is what
 * LeaveEffect debits from the ledger. Nothing carried the per-day figure, so the compute
 * engine priced a leave day at the employee's resolved scheduledMinutes instead — a
 * different number. `day_part` was written at submit and read by nothing downstream, so a
 * half-day paid like a full day; and a 4x10 employee debited 480 while being paid 600.
 *
 * Snapshotted at submit rather than read live from offices.minutes_per_leave_day, so an
 * admin lowering that value cannot restate leave already filed and approved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_details', function (Blueprint $table): void {
            $table->integer('minutes_per_day')->nullable();
        });

        // Backfill with the same formula SubmitLeaveRequestController used to compute the
        // per-day figure it never persisted: half the office value for a half-day, the whole
        // value for a full day.
        DB::statement(<<<'SQL'
            UPDATE leave_details ld
            SET minutes_per_day = CASE
                WHEN ld.day_part = 'half' THEN o.minutes_per_leave_day / 2
                ELSE o.minutes_per_leave_day
            END
            FROM requests r
            JOIN employees e ON e.id = r.employee_id
            JOIN offices o ON o.id = e.current_office_id
            WHERE ld.request_id = r.id
        SQL);

        DB::statement('ALTER TABLE leave_details ALTER COLUMN minutes_per_day SET NOT NULL');
        DB::statement('ALTER TABLE leave_details ADD CONSTRAINT leave_details_minutes_per_day_check CHECK (minutes_per_day > 0)');
    }

    public function down(): void
    {
        Schema::table('leave_details', function (Blueprint $table): void {
            $table->dropColumn('minutes_per_day');
        });
    }
};
