<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_attendance_summaries', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('employee_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->text('day_type');
            $table->boolean('is_rest_day');
            $table->integer('scheduled_minutes');
            $table->boolean('is_art82_exempt');
            $table->foreignUuid('rule_version_id')->nullable()->constrained('pay_rules')->restrictOnDelete();
            $table->integer('worked_minutes');
            $table->integer('late_minutes');
            $table->integer('undertime_minutes');
            $table->text('status')->default('pending');
            $table->boolean('is_incomplete')->default(false);
            $table->timestampTz('computed_at')->nullable();
            $table->timestampsTz();
            $table->unique(['employee_id', 'date']);
        });
        DB::statement("ALTER TABLE daily_attendance_summaries ADD CONSTRAINT das_day_type_check CHECK (day_type IN ('ordinary','special_working','special_non_working','regular_holiday','double_regular_holiday'))");
        DB::statement("ALTER TABLE daily_attendance_summaries ADD CONSTRAINT das_status_check CHECK (status IN ('pending','computed','disputed','locked'))");
        DB::statement('ALTER TABLE daily_attendance_summaries ADD CONSTRAINT das_minutes_nonneg_check CHECK (scheduled_minutes >= 0 AND worked_minutes >= 0 AND late_minutes >= 0 AND undertime_minutes >= 0)');

        Schema::create('daily_summary_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('summary_id')->constrained('daily_attendance_summaries')->cascadeOnDelete();
            $table->text('kind');
            $table->integer('minutes');
            $table->integer('applied_bp');
            $table->timestampsTz();
            $table->unique(['summary_id', 'kind']);
        });
        DB::statement("ALTER TABLE daily_summary_lines ADD CONSTRAINT dsl_kind_check CHECK (kind IN ('regular_day','regular_night','overtime_day','overtime_night','holiday_unworked'))");
        DB::statement('ALTER TABLE daily_summary_lines ADD CONSTRAINT dsl_minutes_pos_check CHECK (minutes > 0 AND applied_bp >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_summary_lines');
        Schema::dropIfExists('daily_attendance_summaries');
    }
};
