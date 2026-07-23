<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The effective-dated source of truth. One row per change; effective_to is derived (the
 * day before the next row's effective_from), never stored. The pay engine (M5) reads the
 * row whose range covers the date it is computing, so a promotion never rewrites history.
 * See docs/02-data-model.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employment_records', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('employee_id')->constrained()->cascadeOnDelete();
            $table->date('effective_from');

            $table->foreignUuid('office_id')->constrained('offices');
            $table->foreignUuid('department_id')->constrained('departments');
            $table->foreignUuid('reports_to_id')->nullable()->constrained('employees');

            $table->text('employment_type');           // 'regular', 'probationary', 'contractual'
            $table->boolean('is_art82_exempt')->default(false);
            $table->bigInteger('base_rate_cents');      // integer centavos, per the money rule

            $table->foreignUuid('created_by')->nullable()->constrained('users');
            $table->timestamps();

            // At most one record per employee per effective date — two changes the same day
            // are one change.
            $table->unique(['employee_id', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employment_records');
    }
};
