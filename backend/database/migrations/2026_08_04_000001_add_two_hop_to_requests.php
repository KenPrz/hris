<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Widens the request spine from one hop to two: leave requests go
 * pending -> manager_approved -> approved, so this adds the intermediate state to the
 * CHECK and the columns that record the manager's (hop-1) decision, mirroring the
 * existing decided_by/decided_at pair used for the final decision. Additive only: no
 * existing row's state or decision columns change, and the single-step attendance
 * adjustment flow is unaffected until later tasks start writing manager_approved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requests', function (Blueprint $table): void {
            $table->foreignUuid('manager_decided_by')->nullable()->after('decided_at')->constrained('users')->nullOnDelete();
            $table->timestampTz('manager_decided_at')->nullable()->after('manager_decided_by');
        });

        DB::statement('ALTER TABLE requests DROP CONSTRAINT requests_state_check');
        DB::statement("ALTER TABLE requests ADD CONSTRAINT requests_state_check CHECK (state IN ('pending','manager_approved','approved','rejected','cancelled'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE requests DROP CONSTRAINT requests_state_check');
        DB::statement("ALTER TABLE requests ADD CONSTRAINT requests_state_check CHECK (state IN ('pending','approved','rejected','cancelled'))");

        Schema::table('requests', fn (Blueprint $table) => $table->dropColumn(['manager_decided_by', 'manager_decided_at']));
    }
};
