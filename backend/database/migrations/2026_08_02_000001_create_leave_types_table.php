<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Per-office leave type config, edited by an HR admin the same way holidays and shift
 * templates are. deducts_balance is the balance-vs-event axis: true = the type holds a
 * balance an employee is granted into (SIL/VL/SL); false = an event entitlement with no
 * balance (Maternity etc., used starting M6b-b). See docs/02-data-model.md. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_types', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('office_id')->constrained()->cascadeOnDelete();
            $table->text('name');
            $table->text('code')->nullable();               // slug for a seeded statutory type; null for ad-hoc
            $table->boolean('is_paid')->default(true);
            $table->boolean('requires_attachment')->default(false);
            $table->boolean('deducts_balance')->default(true);   // false = event entitlement (Maternity etc.)
            $table->boolean('is_cash_convertible')->default(false);
            $table->integer('max_carryover_minutes')->nullable(); // null = unlimited; the year-end job that uses it is deferred
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->unique(['office_id', 'code']);            // one 'sil' per office; multiple null codes allowed (Postgres treats NULLs distinct)
        });

        DB::statement('ALTER TABLE leave_types ADD CONSTRAINT leave_types_max_carryover_nonneg_check CHECK (max_carryover_minutes IS NULL OR max_carryover_minutes >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_types');
    }
};
