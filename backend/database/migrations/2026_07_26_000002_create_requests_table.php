<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The shared request/approval spine. Leave and overtime reuse this table and its state
 * machine; each type gets its own 1:1 detail table. See docs/02-data-model.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('requests', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->text('type');
            $table->foreignUuid('employee_id')->constrained();   // the requester
            $table->text('state')->default('pending');
            $table->text('note');                                 // required
            $table->foreignUuid('decided_by')->nullable()->constrained('users');
            $table->timestampTz('decided_at')->nullable();
            $table->text('decision_note')->nullable();            // required on rejection (app-enforced)
            $table->timestampsTz();

            $table->index(['employee_id', 'state']);
            $table->index(['type', 'state']);                     // the approval queue query
        });

        DB::statement("ALTER TABLE requests ADD CONSTRAINT requests_type_check CHECK (type IN ('attendance_adjustment'))");
        DB::statement("ALTER TABLE requests ADD CONSTRAINT requests_state_check CHECK (state IN ('pending','approved','rejected','cancelled'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('requests');
    }
};
