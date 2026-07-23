<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The annulment ledger: which punches an approved "void" adjustment supersedes. Append-only
 * — a void never touches the attendance_logs row it annuls, it records a new fact here.
 * `unique(attendance_log_id)` makes "at most one annulment per punch" a database invariant,
 * not just an application check. See docs/02-data-model.md and the M3.6 spec.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_annulments', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('attendance_log_id')->unique()->constrained('attendance_logs');
            $table->foreignUuid('request_id')->constrained('requests');
            $table->timestampTz('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_annulments');
    }
};
