<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('shift_templates', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('office_id')->constrained()->cascadeOnDelete();
            $table->text('name');
            $table->timestamps();
            $table->index('office_id');
        });

        Schema::create('shift_template_days', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('shift_template_id')->constrained()->cascadeOnDelete();
            $table->smallInteger('weekday');
            $table->boolean('is_rest');
            $table->smallInteger('start_minute')->nullable();
            $table->smallInteger('end_minute')->nullable();
            $table->smallInteger('break_minutes')->nullable();
            $table->timestamps();
            $table->unique(['shift_template_id', 'weekday']);
        });

        DB::statement('ALTER TABLE shift_template_days ADD CONSTRAINT shift_template_days_weekday_check CHECK (weekday BETWEEN 0 AND 6)');
        // is_rest XOR hours: rest => all three null; working => all three present.
        DB::statement(<<<'SQL'
            ALTER TABLE shift_template_days ADD CONSTRAINT shift_template_days_rest_xor_hours_check CHECK (
              (is_rest = true  AND start_minute IS NULL AND end_minute IS NULL AND break_minutes IS NULL)
              OR
              (is_rest = false AND start_minute IS NOT NULL AND end_minute IS NOT NULL AND break_minutes IS NOT NULL)
            )
        SQL);
        // working-row minute ranges (only checked when hours present; rest rows short-circuit).
        DB::statement(<<<'SQL'
            ALTER TABLE shift_template_days ADD CONSTRAINT shift_template_days_minutes_check CHECK (
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
        Schema::dropIfExists('shift_template_days');
        Schema::dropIfExists('shift_templates');
    }
};
