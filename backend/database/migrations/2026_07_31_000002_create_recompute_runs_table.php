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
        Schema::create('recompute_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->text('trigger_type');
            $table->uuid('trigger_id')->nullable();
            $table->text('reason');
            $table->integer('pair_count');
            $table->text('batch_id')->nullable();
            $table->text('status')->default('queued');
            $table->foreignUuid('caused_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE recompute_runs ADD CONSTRAINT recompute_runs_trigger_type_check CHECK (trigger_type IN ('holiday','pay_rule','shift_template','schedule_assignment','schedule_override','office_default'))");
        DB::statement("ALTER TABLE recompute_runs ADD CONSTRAINT recompute_runs_status_check CHECK (status IN ('queued','completed','failed'))");
        DB::statement('ALTER TABLE recompute_runs ADD CONSTRAINT recompute_runs_pair_count_check CHECK (pair_count >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('recompute_runs');
    }
};
