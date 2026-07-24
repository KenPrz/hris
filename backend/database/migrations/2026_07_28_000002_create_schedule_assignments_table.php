<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('schedule_assignments', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('shift_template_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('employee_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUuid('department_id')->nullable()->constrained()->cascadeOnDelete();
            $table->date('effective_from');
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Exactly one of employee_id / department_id must be set.
        DB::statement(<<<'SQL'
            ALTER TABLE schedule_assignments ADD CONSTRAINT schedule_assignments_one_target_check CHECK (
              (employee_id IS NOT NULL AND department_id IS NULL)
              OR (employee_id IS NULL AND department_id IS NOT NULL)
            )
        SQL);
        // Partial uniques: one assignment per target per effective date. Plain unique
        // indexes would not work here — Postgres treats NULLs as distinct in a unique
        // index, but only one column is populated per row, so a partial index scoped to
        // "this column IS NOT NULL" is what actually enforces one-per-target.
        DB::statement('CREATE UNIQUE INDEX schedule_assignments_employee_effective_unique ON schedule_assignments (employee_id, effective_from) WHERE employee_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX schedule_assignments_department_effective_unique ON schedule_assignments (department_id, effective_from) WHERE department_id IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_assignments');
    }
};
