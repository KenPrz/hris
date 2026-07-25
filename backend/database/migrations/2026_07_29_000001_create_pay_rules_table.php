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
        Schema::create('pay_rules', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->date('effective_from')->unique();
            $table->integer('overtime_ordinary_bp');
            $table->integer('overtime_premium_bp');
            $table->integer('night_diff_bp');
            $table->text('note')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        DB::statement('ALTER TABLE pay_rules ADD CONSTRAINT pay_rules_bp_nonneg_check CHECK (overtime_ordinary_bp >= 0 AND overtime_premium_bp >= 0 AND night_diff_bp >= 0)');

        Schema::create('pay_rule_day_rates', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('pay_rule_id')->constrained()->cascadeOnDelete();
            $table->text('day_type');
            $table->integer('worked_bp');
            $table->integer('worked_rest_bp');
            $table->integer('unworked_bp');
            $table->timestamps();
            $table->unique(['pay_rule_id', 'day_type']);
        });
        DB::statement("ALTER TABLE pay_rule_day_rates ADD CONSTRAINT pay_rule_day_rates_day_type_check CHECK (day_type IN ('ordinary','special_working','special_non_working','regular_holiday','double_regular_holiday'))");
        DB::statement('ALTER TABLE pay_rule_day_rates ADD CONSTRAINT pay_rule_day_rates_bp_nonneg_check CHECK (worked_bp >= 0 AND worked_rest_bp >= 0 AND unworked_bp >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('pay_rule_day_rates');
        Schema::dropIfExists('pay_rules');
    }
};
