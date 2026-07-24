<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('schedule_overrides', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('employee_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->boolean('is_rest');
            $table->smallInteger('start_minute')->nullable();
            $table->smallInteger('end_minute')->nullable();
            $table->smallInteger('break_minutes')->nullable();
            $table->text('note')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['employee_id', 'date']);
        });

        // is_rest XOR hours: rest => all three null; working => all three present.
        DB::statement(<<<'SQL'
            ALTER TABLE schedule_overrides ADD CONSTRAINT schedule_overrides_rest_xor_hours_check CHECK (
              (is_rest = true  AND start_minute IS NULL AND end_minute IS NULL AND break_minutes IS NULL)
              OR
              (is_rest = false AND start_minute IS NOT NULL AND end_minute IS NOT NULL AND break_minutes IS NOT NULL)
            )
        SQL);
        // working-row minute ranges (only checked when hours present; rest rows short-circuit).
        DB::statement(<<<'SQL'
            ALTER TABLE schedule_overrides ADD CONSTRAINT schedule_overrides_minutes_check CHECK (
              is_rest = true OR (
                start_minute >= 0 AND start_minute < 1440
                AND end_minute > start_minute AND end_minute <= start_minute + 1440
                AND break_minutes >= 0 AND break_minutes < (end_minute - start_minute)
              )
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_overrides');
    }
};
