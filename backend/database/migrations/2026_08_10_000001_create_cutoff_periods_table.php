<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-office semi-monthly cutoff periods. A period is `open` until CloseCutoff freezes it
 * (`closed`), locking every daily_attendance_summary in its (office, [start,end]) window.
 * The period a summary belongs to is DERIVED by office + date range — no FK is stamped on
 * the summary (no second source of truth to keep consistent across recompute).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cutoff_periods', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('office_id')->constrained()->cascadeOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            $table->text('state')->default('open');
            $table->foreignUuid('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('closed_at')->nullable();
            $table->timestampsTz();
            $table->unique(['office_id', 'start_date']);
        });

        DB::statement("ALTER TABLE cutoff_periods ADD CONSTRAINT cutoff_periods_state_check CHECK (state IN ('open','closed'))");
        DB::statement('ALTER TABLE cutoff_periods ADD CONSTRAINT cutoff_periods_dates_check CHECK (end_date >= start_date)');
    }

    public function down(): void
    {
        Schema::dropIfExists('cutoff_periods');
    }
};
