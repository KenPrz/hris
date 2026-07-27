<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The leave request's 1:1 detail — mirrors attendance_adjustment_details: the primary key
 * IS the request's id (no separate id column, no separate uuid generation here), one
 * request, one detail, enforced by the database rather than by convention. See
 * docs/02-data-model.md and the M6b-b spec.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_details', function (Blueprint $table): void {
            $table->uuid('request_id')->primary();
            $table->foreign('request_id')->references('id')->on('requests')->cascadeOnDelete();
            $table->foreignUuid('leave_type_id')->constrained();

            $table->date('start_date');
            $table->date('end_date');
            $table->text('day_part');
            $table->integer('amount_minutes');
        });

        // text + CHECK, never a Postgres native enum.
        DB::statement("ALTER TABLE leave_details ADD CONSTRAINT leave_details_day_part_check CHECK (day_part IN ('full','half'))");
        DB::statement('ALTER TABLE leave_details ADD CONSTRAINT leave_details_amount_pos_check CHECK (amount_minutes > 0)');
        DB::statement('ALTER TABLE leave_details ADD CONSTRAINT leave_details_dates_check CHECK (end_date >= start_date)');
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_details');
    }
};
