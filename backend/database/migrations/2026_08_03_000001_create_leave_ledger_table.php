<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The leave bank statement: append-only, one row per grant/deduction/correction. A
 * balance is never stored anywhere — LeaveBalances::forEmployee derives it as
 * SUM(credit) - SUM(debit) over this table. A correction is a new compensating row, never
 * an edit of an old one, which is why there is deliberately no updated_at: only
 * created_at is managed, so Eloquent can never touch an existing row after insert. See
 * docs/00-overview.md and docs/02-data-model.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_ledger', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('employee_id')->constrained();
            $table->foreignUuid('leave_type_id')->constrained();
            $table->text('entry_type');
            $table->integer('minutes');
            $table->text('reason');
            $table->text('source');
            $table->foreignUuid('request_id')->nullable()->constrained('requests')->nullOnDelete();
            $table->foreignUuid('created_by')->constrained('users');
            $table->timestampTz('created_at');   // no updated_at — append-only

            $table->index(['employee_id', 'leave_type_id']);
        });

        DB::statement("ALTER TABLE leave_ledger ADD CONSTRAINT leave_ledger_entry_type_check CHECK (entry_type IN ('credit','debit'))");
        DB::statement("ALTER TABLE leave_ledger ADD CONSTRAINT leave_ledger_source_check CHECK (source IN ('manual_grant'))");
        DB::statement('ALTER TABLE leave_ledger ADD CONSTRAINT leave_ledger_minutes_pos_check CHECK (minutes > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_ledger');
    }
};
