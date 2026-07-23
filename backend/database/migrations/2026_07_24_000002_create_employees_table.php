<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The core record: immutable identity plus three denormalized current_* columns.
 *
 * The current_* columns are a cache of the employee's active employment_records row,
 * written ONLY by App\Actions\Employees\RecordEmploymentChange, in the same transaction
 * as the history row it derives from. An arch test enforces the single writer. They exist
 * so office scoping stays a plain `WHERE current_office_id = ?` rather than a join to a
 * derived row. See docs/02-data-model.md and docs/05-rbac.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->text('employee_no')->unique();
            // Nullable: a punch-only worker has an employee record and no login.
            $table->foreignUuid('user_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->foreignUuid('organization_id')->constrained();
            $table->date('hired_at');
            $table->date('separated_at')->nullable();

            // The cache. Nullable because an employee exists for the instant between the
            // employees insert and the first RecordEmploymentChange, within one transaction.
            $table->foreignUuid('current_office_id')->nullable()->constrained('offices');
            $table->foreignUuid('current_department_id')->nullable()->constrained('departments');
            // Self-referencing FK deliberately left unconstrained here — see the
            // Schema::table() call below for why.
            $table->foreignUuid('current_reports_to_id')->nullable();

            $table->timestamps();

            $table->index('current_office_id');
            $table->index('current_reports_to_id');
        });

        // Postgres grammar appends the fluent `->primary()` on `id` to the END of this
        // blueprint's command list, after any inline `->constrained()` foreign keys
        // defined earlier in the same Schema::create(). current_reports_to_id
        // self-references employees.id, so adding its FK inline would run before
        // employees' own primary key exists in this migration, and Postgres rejects
        // that ordering ("no unique constraint matching given keys"). Adding it in a
        // follow-up Schema::table() call sidesteps the ordering entirely.
        Schema::table('employees', function (Blueprint $table): void {
            $table->foreign('current_reports_to_id')->references('id')->on('employees');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
