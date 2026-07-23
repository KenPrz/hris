<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The append-only attendance ledger — the raw record shown a DOLE inspector. Nothing ever
 * updates or deletes a row; a correction is a new (manual) row. Enum-valued columns are
 * text + CHECK, cast to PHP backed enums in the model — never a Postgres native enum type,
 * which is a migration dance to alter. See docs/02-data-model.md and the M3 spec.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('employee_id')->constrained();
            // Snapshot: the office the punch belonged to at the instant it happened.
            $table->foreignUuid('office_id')->constrained('offices');
            $table->timestampTz('punched_at');

            $table->text('direction');
            $table->text('source');
            $table->text('verification');
            $table->text('flag_reason')->nullable();

            $table->foreignUuid('recorded_by')->nullable()->constrained('users');
            $table->text('ip_address')->nullable();          // inet stored as text; cast in the model
            $table->text('device_id')->nullable();
            $table->decimal('geo_lat', 10, 7)->nullable();
            $table->decimal('geo_lng', 10, 7)->nullable();

            $table->timestampTz('created_at')->useCurrent();

            // The query M5 and the read API run: an employee's punches within a time range.
            $table->index(['employee_id', 'punched_at']);
        });

        // The enum cases live in app/Domain/Attendance/*; these CHECK lists must match them
        // (AttendanceLogSchemaTest pins both). text + CHECK, never a Postgres native enum.
        DB::statement("ALTER TABLE attendance_logs ADD CONSTRAINT attendance_logs_direction_check CHECK (direction IN ('in','out'))");
        DB::statement("ALTER TABLE attendance_logs ADD CONSTRAINT attendance_logs_source_check CHECK (source IN ('web','manual','device'))");
        DB::statement("ALTER TABLE attendance_logs ADD CONSTRAINT attendance_logs_verification_check CHECK (verification IN ('verified','flagged'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_logs');
    }
};
