<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Per-office holiday calendar. A holiday maps a calendar date to a non-Ordinary DayType;
 * Ordinary is the absence of a row. See docs/02-data-model.md. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('holidays', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('office_id')->constrained()->cascadeOnDelete();
            $table->date('date');                 // a calendar date, no timezone
            $table->text('day_type');
            $table->text('name');
            $table->timestampsTz();

            $table->unique(['office_id', 'date']);
        });

        DB::statement("ALTER TABLE holidays ADD CONSTRAINT holidays_day_type_check CHECK (day_type IN ('special_working','special_non_working','regular_holiday','double_regular_holiday'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('holidays');
    }
};
