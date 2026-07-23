<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The attendance-adjustment request's 1:1 detail — the type-specific columns a plain
 * `requests` row can't hold. The primary key IS the request's id (no separate id column,
 * no separate uuid generation here): one request, one detail, enforced by the database
 * rather than by convention. See docs/02-data-model.md and the M3.6 spec.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_adjustment_details', function (Blueprint $table): void {
            $table->uuid('request_id')->primary();
            $table->foreign('request_id')->references('id')->on('requests')->cascadeOnDelete();

            $table->text('operation');
            $table->foreignUuid('target_log_id')->nullable()->constrained('attendance_logs');
            $table->text('direction')->nullable();
            $table->timestampTz('punched_at')->nullable();
        });

        // The enum cases live in app/Domain/Attendance/*; these CHECK lists must match them
        // (AdjustmentSchemaTest pins both). text + CHECK, never a Postgres native enum.
        DB::statement("ALTER TABLE attendance_adjustment_details ADD CONSTRAINT attendance_adjustment_details_operation_check CHECK (operation IN ('add','void','amend'))");
        DB::statement("ALTER TABLE attendance_adjustment_details ADD CONSTRAINT attendance_adjustment_details_direction_check CHECK (direction IS NULL OR direction IN ('in','out'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_adjustment_details');
    }
};
